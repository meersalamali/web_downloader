<?php
declare(strict_types=1);

/**
 * The crawl engine. Work is done in short "ticks" so the browser can poll for
 * progress and nothing ever hits PHP's execution time limit:
 *
 *   init -> crawl -> rewrite -> package -> done
 *
 * init and crawl live here; rewrite and package are handled by Packager.
 */
final class Crawler
{
    private Job $job;
    private Http $http;
    private Robots $robots;
    /** @var array<string,string> local path => url, used to keep file names unique */
    private array $used = [];

    public function __construct(Job $job)
    {
        $this->job = $job;
        $this->http = new Http([
            'user_agent' => (string) $job->opt('user_agent', ''),
            'timeout'    => (int) $job->opt('timeout', 25),
            'max_bytes'  => (int) $job->opt('max_file_mb', 25) * 1024 * 1024,
            'verify_ssl' => (bool) $job->opt('verify_ssl', false),
            'cookie'     => (string) $job->opt('cookie', ''),
            'auth_user'  => (string) $job->opt('auth_user', ''),
            'auth_pass'  => (string) $job->opt('auth_pass', ''),
        ]);
        $this->robots = is_array($job->meta['robots'] ?? null)
            ? Robots::fromArray($job->meta['robots'])
            : Robots::none();
    }

    /** Do a slice of work and return a UI snapshot. */
    public function tick(int $logFrom = 0): array
    {
        @set_time_limit(0);

        if (!$this->job->acquireLock()) {
            $snap = $this->job->snapshot($logFrom);
            $snap['busy'] = true;
            return $snap;
        }

        try {
            if ($this->job->isRunning()) {
                $deadline = microtime(true) + (float) sg_config('tick_seconds', 6.0);
                switch ($this->job->phase()) {
                    case Job::PHASE_INIT:
                        $this->initPhase();
                        break;
                    case Job::PHASE_CRAWL:
                        $this->crawlPhase($deadline);
                        break;
                    case Job::PHASE_REWRITE:
                        (new Packager($this->job))->rewriteSlice($deadline);
                        break;
                    case Job::PHASE_PACKAGE:
                        (new Packager($this->job))->packageSlice($deadline);
                        break;
                }
                $this->job->save();
            }
        } catch (Throwable $e) {
            $this->job->log('error', 'Stopped: ' . $e->getMessage());
            $this->job->finish('error', $e->getMessage());
            try {
                $this->job->save();
            } catch (Throwable $ignored) {
                // nothing useful left to do
            }
        } finally {
            $this->job->releaseLock();
        }

        return $this->job->snapshot($logFrom);
    }

    /* ------------------------------------------------------------------ init */

    private function initPhase(): void
    {
        $job = $this->job;
        $job->loadQueue();
        $job->loadRes();

        $root = (string) $job->meta['root_url'];
        $host = (string) $job->meta['host'];
        $job->log('info', 'Starting on ' . $root);

        // Fetch robots.txt first so we honour it from the very first request.
        if ($job->opt('respect_robots', true)) {
            $robotsUrl = preg_replace('#^(https?://[^/]+).*$#', '$1/robots.txt', $root);
            $r = $this->http->get((string) $robotsUrl);
            if ($r['status'] === 200 && $r['body'] !== '') {
                $this->robots = Robots::parse($r['body'], 'sitegrabber');
                $job->meta['robots'] = $this->robots->toArray();
                $n = count($this->robots->toArray()['rules']);
                $job->log('info', 'robots.txt loaded (' . $n . ' rules'
                    . ($this->robots->crawlDelay() > 0 ? ', crawl-delay ' . $this->robots->crawlDelay() . 's' : '') . ')');
            } else {
                $job->log('muted', 'No robots.txt found - crawling everything in scope');
            }
        } else {
            $job->log('warn', 'robots.txt is being ignored (you turned the option off)');
        }

        // Record what the server tells us about itself.
        $head = $this->http->get($root);
        $job->bump('requests');
        if ($head['status'] === 0) {
            throw new RuntimeException('Cannot reach ' . $root . ' - ' . $head['error']);
        }
        $job->meta['server'] = array_filter([
            'server'        => $head['headers']['server'] ?? '',
            'powered_by'    => $head['headers']['x-powered-by'] ?? '',
            'content_type'  => $head['ctype'],
            'status'        => (string) $head['status'],
            'generator'     => self::metaGenerator($head['body']),
        ]);

        $job->enqueue($root, 0, 'page', 'html');
        if ($head['final_url'] !== $root && Url::sameSite($head['final_url'], $host, true)) {
            $job->log('info', 'Redirected to ' . $head['final_url']);
            $job->enqueue($head['final_url'], 0, 'page', 'html');
        }

        if ($job->opt('use_sitemap', true)) {
            $this->seedFromSitemaps($root);
        }

        $job->setPhase(Job::PHASE_CRAWL);
        $job->log('info', 'Queued ' . $job->queueSize() . ' URL(s) to start');
    }

    private static function metaGenerator(string $html): string
    {
        if (preg_match('#<meta[^>]+name=["\']generator["\'][^>]+content=["\']([^"\']+)#i', $html, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    /** sitemap.xml finds pages that nothing links to, which plain crawling would miss. */
    private function seedFromSitemaps(string $root): void
    {
        $job = $this->job;
        $base = (string) preg_replace('#^(https?://[^/]+).*$#', '$1', $root);

        $todo = $this->robots->sitemaps();
        $todo[] = $base . '/sitemap.xml';
        $todo[] = $base . '/sitemap_index.xml';
        $todo = array_values(array_unique($todo));

        $seen = [];
        $added = 0;
        $fetches = 0;

        while ($todo && $fetches < 12 && $added < 3000) {
            $url = array_shift($todo);
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $fetches++;

            $r = $this->http->get($url);
            $job->bump('requests');
            if ($r['status'] !== 200 || $r['body'] === '') {
                continue;
            }
            $body = $r['body'];
            if (str_starts_with($body, "\x1f\x8b")) {
                $body = (string) @gzdecode($body);
            }
            if (!str_contains($body, '<loc')) {
                continue;
            }

            $isIndex = stripos($body, '<sitemapindex') !== false;
            if (!preg_match_all('#<loc>\s*(.*?)\s*</loc>#is', $body, $m)) {
                continue;
            }
            foreach ($m[1] as $loc) {
                $loc = Url::normalize(html_entity_decode(trim($loc), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($loc === '') {
                    continue;
                }
                if ($isIndex) {
                    $todo[] = $loc;
                    continue;
                }
                if (!$this->inScope($loc) || !$this->allowedByRobots($loc)) {
                    continue;
                }
                if ($job->enqueue($loc, 1, 'page', 'html')) {
                    $added++;
                }
                if ($added >= 3000) {
                    break;
                }
            }
            $job->log('info', 'sitemap ' . $url . ' -> ' . $added . ' page(s) queued so far');
        }

        if ($added > 0) {
            $job->log('ok', 'Sitemap added ' . $added . ' page(s)');
        }
    }

    /* ------------------------------------------------------------------ crawl */

    private function crawlPhase(float $deadline): void
    {
        $job = $this->job;
        $job->loadQueue();
        $job->loadRes();
        $this->buildUsedPaths();

        $concurrency = (int) Util::clamp((int) $job->opt('concurrency', 5), 1, 12);
        $delayMs = (int) Util::clamp((int) $job->opt('delay_ms', 150), 0, 10000);
        $robotsDelay = (int) round($this->robots->crawlDelay() * 1000);
        $delayMs = max($delayMs, $robotsDelay);

        while (microtime(true) < $deadline) {
            if (!$this->enforceLimits()) {
                break;
            }
            $batch = $job->takeBatch($concurrency);
            if (!$batch) {
                break;
            }

            $fetch = [];
            foreach ($batch as [$url, $depth, $kind, $type]) {
                $reason = $this->rejectReason($url, $kind, $type);
                if ($reason !== null) {
                    $job->markDone($url, ['st' => 'skip', 'e' => $reason]);
                    $job->bump('skipped');
                    continue;
                }
                $fetch[$url] = [$depth, $kind, $type];
            }
            if (!$fetch) {
                continue;
            }

            $this->http->getMany(array_keys($fetch), function (array $r) use ($fetch): void {
                [$depth, $kind, $type] = $fetch[$r['url']] ?? [0, 'asset', ''];
                $this->handle($r, $depth, $kind, $type);
            });
            $job->bump('requests', count($fetch));

            if ($delayMs > 0 && microtime(true) < $deadline) {
                usleep($delayMs * 1000);
            }
        }

        if ($job->queueSize() === 0 || !$this->enforceLimits()) {
            $job->setPhase(Job::PHASE_REWRITE);
            $job->meta['rewrite_at'] = 0;
            $job->log('ok', 'Download finished - ' . $job->counter('pages_done') . ' page(s), '
                . $job->counter('assets_done') . ' file(s), ' . Util::bytes($job->counter('bytes')));
            $job->log('info', 'Rewriting links so the copy works offline...');
        }
    }

    /** @return bool false when the crawl must stop entirely */
    private function enforceLimits(): bool
    {
        $job = $this->job;
        $maxTotal = (int) $job->opt('max_total_mb', 600) * 1024 * 1024;
        if ($job->counter('bytes') >= $maxTotal) {
            $job->note('Stopped at the total size limit of ' . $job->opt('max_total_mb') . ' MB.');
            $job->log('warn', 'Total size limit reached - stopping download');
            return false;
        }

        $pagesSpent  = $job->counter('pages_done') >= (int) $job->opt('max_pages', 150);
        $assetsSpent = $job->counter('assets_done') >= (int) $job->opt('max_assets', 1500);

        if ($pagesSpent || $assetsSpent) {
            $before = $job->queueSize();
            $job->queue = array_values(array_filter($job->queue, static function (array $row) use ($pagesSpent, $assetsSpent) {
                $isPage = ($row[2] ?? '') === 'page';
                return $isPage ? !$pagesSpent : !$assetsSpent;
            }));
            $dropped = $before - $job->queueSize();
            if ($dropped > 0) {
                if ($pagesSpent) {
                    $job->note('Reached the page limit (' . $job->opt('max_pages') . '). '
                        . 'Raise "Max pages" to go deeper.');
                }
                if ($assetsSpent) {
                    $job->note('Reached the file limit (' . $job->opt('max_assets') . ').');
                }
                $job->log('warn', 'Limit reached - dropped ' . $dropped . ' queued URL(s)');
            }
        }
        return $job->queueSize() > 0;
    }

    /** Why we will not download this URL, or null to go ahead. */
    private function rejectReason(string $url, string $kind, string $type): ?string
    {
        $job = $this->job;

        if (!Url::isHttp($url)) {
            return 'not an http(s) URL';
        }
        if (!$this->allowedByRobots($url)) {
            return 'blocked by robots.txt';
        }
        if ($kind === 'page' && !$this->inScope($url)) {
            return 'outside the site';
        }
        if ($kind === 'asset' && !$this->inScope($url) && !$job->opt('external_assets', true)) {
            return 'external file (option off)';
        }

        $types = (array) $job->opt('types', []);
        if ($type !== '' && $type !== 'html' && array_key_exists($type, $types) && !$types[$type]) {
            return $type . ' files are turned off';
        }

        $skipExt = array_filter(array_map('trim', explode(',', strtolower((string) $job->opt('skip_ext', '')))));
        if ($skipExt !== []) {
            $ext = Url::extension($url);
            if ($ext !== '' && in_array($ext, $skipExt, true)) {
                return '.' . $ext . ' is on your skip list';
            }
        }
        return null;
    }

    private function inScope(string $url): bool
    {
        return Url::sameSite($url, (string) $this->job->meta['host'], (bool) $this->job->opt('include_subdomains', false));
    }

    private function allowedByRobots(string $url): bool
    {
        if (!$this->job->opt('respect_robots', true)) {
            return true;
        }
        // Rules only apply to the host they came from.
        if (!$this->inScope($url)) {
            return true;
        }
        return $this->robots->allows($url);
    }

    /* ------------------------------------------------------------------ one response */

    private function handle(array $r, int $depth, string $kind, string $type): void
    {
        $job = $this->job;
        $url = $r['url'];

        if ($r['error'] !== '' || $r['status'] >= 400 || $r['status'] === 0) {
            $job->markDone($url, ['st' => 'fail', 'c' => $r['status'], 'e' => $r['error'] ?: 'failed']);
            $job->bump($kind === 'page' ? 'pages_failed' : 'assets_failed');
            $job->log('error', ($r['status'] ?: 'ERR') . '  ' . $this->shortUrl($url) . '  ' . $r['error']);
            return;
        }

        // Trust the Content-Type header over the file extension.
        $realType = Url::typeFromMime($r['ctype']);
        if ($realType === '') {
            $realType = $type !== '' ? $type : 'doc';
        }
        // A link we assumed was a page but is really a download, or the reverse.
        if ($kind === 'page' && $realType !== 'html') {
            $kind = 'asset';
        }

        $typesOpt = (array) $job->opt('types', []);
        if ($realType !== 'html' && array_key_exists($realType, $typesOpt) && !$typesOpt[$realType]) {
            $job->markDone($url, ['st' => 'skip', 'c' => $r['status'], 'e' => $realType . ' files are turned off']);
            $job->bump('skipped');
            return;
        }

        $final = $r['final_url'];
        if ($kind === 'page' && $final !== $url && !$this->inScope($final)) {
            $job->markDone($url, ['st' => 'skip', 'c' => $r['status'], 'e' => 'redirected off-site']);
            $job->bump('skipped');
            $job->log('muted', 'SKIP ' . $this->shortUrl($url) . ' - redirected off-site');
            return;
        }

        // Store the bytes untouched; rewriting happens in a later phase.
        $hash = sha1($r['body']);
        [$path, $isTwin] = $this->assignPath($final !== '' ? $final : $url, $realType, $hash);

        $rawName = '';
        if (!$isTwin) {
            $rawName = sha1($url) . '.dat';
            if (@file_put_contents($job->dir('raw/' . $rawName), $r['body']) === false) {
                throw new RuntimeException('Cannot write to storage folder: ' . $job->dir('raw'));
            }
        }

        $job->markDone($url, [
            'st' => 'ok', 'c' => $r['status'], 'z' => $r['size'],
            't' => $realType, 'k' => $kind, 'p' => $path, 'raw' => $rawName, 'h' => $hash,
        ]);
        // A redirect means two URLs point at the same file.
        if ($final !== $url && !$job->seen($final)) {
            $job->markDone($final, ['st' => 'ok', 'c' => $r['status'], 'z' => 0, 't' => $realType,
                                    'k' => $kind, 'p' => $path, 'raw' => '']);
        }

        $job->bump($kind === 'page' ? 'pages_done' : 'assets_done');
        $job->bump('bytes', $r['size']);
        if (Url::isDynamic($url)) {
            $job->meta['dynamic'] = (int) ($job->meta['dynamic'] ?? 0) + 1;
        }
        $job->log($kind === 'page' ? 'ok' : 'asset',
            $r['status'] . '  ' . $this->shortUrl($url) . '  ' . Util::bytes($r['size']),
            ['p' => $path]);

        $this->discover($r, $final !== '' ? $final : $url, $depth, $realType);
    }

    /** Pull new URLs out of what we just downloaded. */
    private function discover(array $r, string $baseUrl, int $depth, string $realType): void
    {
        $job = $this->job;
        $maxDepth = (int) $job->opt('max_depth', 6);
        $found = [];

        if ($realType === 'html') {
            $base = Rewriter::baseHref($r['body'], $baseUrl);
            $found = Rewriter::extractHtml($r['body'], $base, (bool) $job->opt('scan_js', false));
        } elseif ($realType === 'css') {
            $found = Rewriter::extractCss($r['body'], $baseUrl);
        } elseif ($realType === 'js' && $job->opt('scan_js', false)) {
            $found = Rewriter::extractJs($r['body'], $baseUrl);
        }

        $pages = 0;
        $assets = 0;
        foreach ($found as $hit) {
            $url = $hit['url'];
            if ($url === '' || $job->seen($url)) {
                continue;
            }
            if ($hit['kind'] === 'page') {
                if ($depth + 1 > $maxDepth || !$this->inScope($url)) {
                    continue;
                }
                if ($job->enqueue($url, $depth + 1, 'page', 'html')) {
                    $pages++;
                }
            } else {
                if ($job->enqueue($url, $depth, 'asset', $hit['type'])) {
                    $assets++;
                }
            }
        }
        if ($pages + $assets > 0) {
            $job->log('muted', '   found ' . $pages . ' new page(s), ' . $assets . ' new file(s)');
        }
    }

    /* ------------------------------------------------------------------ paths */

    private function buildUsedPaths(): void
    {
        if ($this->used !== []) {
            return;
        }
        foreach ($this->job->res as $url => $rec) {
            if (($rec['p'] ?? '') !== '') {
                $this->used[$rec['p']] = $url;
            }
        }
    }

    /**
     * A unique local path for this URL.
     *
     * When the natural name is taken by a URL in the same directory that returned
     * byte-identical content, both share one file instead of producing
     * index.html plus index-2.html. Restricting this to a shared directory keeps
     * it safe: relative links inside the body then resolve the same way for both.
     *
     * @return array{0:string,1:bool} [path, isTwin]
     */
    private function assignPath(string $url, string $type, string $hash): array
    {
        $path = Url::toLocalPath($url, $type, (string) $this->job->meta['host']);

        $occupant = $this->used[$path] ?? null;
        if ($occupant === null || $occupant === $url) {
            $this->used[$path] = $url;
            return [$path, false];
        }

        $rec = $this->job->res[$occupant] ?? null;
        if ($rec !== null && ($rec['h'] ?? '') === $hash && self::sameDir($occupant, $url)) {
            return [$path, true];
        }

        $dir  = str_contains($path, '/') ? substr($path, 0, strrpos($path, '/') + 1) : '';
        $file = basename($path);
        $ext  = pathinfo($file, PATHINFO_EXTENSION);
        $stem = pathinfo($file, PATHINFO_FILENAME);

        for ($i = 2; $i < 9999; $i++) {
            $cand = $dir . $stem . '-' . $i . ($ext !== '' ? '.' . $ext : '');
            if (!isset($this->used[$cand])) {
                $this->used[$cand] = $url;
                return [$cand, false];
            }
        }
        $cand = $dir . $stem . '-' . substr(sha1($url), 0, 8) . ($ext !== '' ? '.' . $ext : '');
        $this->used[$cand] = $url;
        return [$cand, false];
    }

    /** Do two URLs sit in the same directory on the same host? */
    private static function sameDir(string $a, string $b): bool
    {
        if (Url::host($a) !== Url::host($b)) {
            return false;
        }
        $dir = static function (string $u): string {
            $p = Url::path($u);
            $cut = strrpos($p, '/');
            return $cut === false ? '/' : substr($p, 0, $cut + 1);
        };
        return $dir($a) === $dir($b);
    }

    private function shortUrl(string $url): string
    {
        $p = parse_url($url);
        $s = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
        if (($p['host'] ?? '') !== $this->job->meta['host']) {
            $s = ($p['host'] ?? '') . $s;
        }
        return strlen($s) > 95 ? substr($s, 0, 92) . '...' : $s;
    }
}
