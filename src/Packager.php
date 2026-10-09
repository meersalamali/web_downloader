<?php
declare(strict_types=1);

/**
 * Phases three and four: turn the raw download into an offline-ready folder,
 * then compress it. Both run in slices so a large site never blocks a request.
 */
final class Packager
{
    private Job $job;

    public function __construct(Job $job)
    {
        $this->job = $job;
    }

    /* ------------------------------------------------------------------ rewrite */

    /** Write raw bytes into site/ with every link pointing at the local copy. */
    public function rewriteSlice(float $deadline): void
    {
        $job = $this->job;
        $job->loadRes();

        $index = [];
        foreach ($job->res as $url => $rec) {
            if (($rec['st'] ?? '') === 'ok' && ($rec['p'] ?? '') !== '') {
                $index[$url] = $rec['p'];
            }
        }

        $keys = array_keys($job->res);
        $total = count($keys);
        $i = (int) ($job->meta['rewrite_at'] ?? 0);
        $keepAbsolute = (bool) $job->opt('keep_absolute', true);
        $stripIntegrity = (bool) $job->opt('strip_integrity', true);
        $written = 0;

        while ($i < $total && microtime(true) < $deadline) {
            $url = $keys[$i];
            $i++;
            $rec = $job->res[$url];
            if (($rec['st'] ?? '') !== 'ok' || ($rec['p'] ?? '') === '' || ($rec['raw'] ?? '') === '') {
                continue;
            }

            $rawFile = $job->dir('raw/' . $rec['raw']);
            if (!is_file($rawFile)) {
                continue;
            }
            $target = $job->dir('site/' . $rec['p']);
            Util::ensureDir(dirname($target));

            $type = $rec['t'] ?? '';
            if ($type === 'html' || $type === 'css') {
                $body = (string) @file_get_contents($rawFile);
                $selfPath = (string) $rec['p'];

                $map = static function (string $u) use ($index, $selfPath, $keepAbsolute): ?string {
                    $to = $index[$u] ?? null;
                    if ($to === null) {
                        // Not downloaded. Writing the fully qualified URL matters:
                        // a root-relative "/x.html" left as-is would point at the
                        // filesystem root once the archive is opened from disk.
                        return $keepAbsolute ? $u : '#not-downloaded';
                    }
                    return Util::relativePath($selfPath, $to);
                };

                if ($type === 'html') {
                    $base = Rewriter::baseHref($body, $url);
                    $body = Rewriter::walkHtml($body, $base, $map,
                        ['scan_js' => false, 'strip_integrity' => $stripIntegrity]);
                } else {
                    $body = Rewriter::walkCss($body, $url, $map);
                }
                @file_put_contents($target, $body);
            } else {
                @copy($rawFile, $target);
            }
            $written++;
        }

        $job->meta['rewrite_at'] = $i;

        if ($written > 0) {
            $job->log('muted', '   rewrote ' . $i . ' / ' . $total . ' file(s)');
        }
        if ($i >= $total) {
            $job->setPhase(Job::PHASE_PACKAGE);
            $job->meta['pack_at'] = 0;
            $job->meta['pack_total'] = null;
            $job->log('ok', 'Links rewritten - all pages now work offline');
            $job->log('info', 'Building the ZIP archive...');
        }
    }

    /* ------------------------------------------------------------------ package */

    public function packageSlice(float $deadline): void
    {
        $job = $this->job;
        $job->loadRes();

        $listFile = $job->dir('pack.json');
        if ($job->meta['pack_total'] === null || !is_file($listFile)) {
            // Write the docs into site/ before listing, so they are both zipped
            // and browsable in the preview without extracting anything.
            $this->writeDocs();
            $files = $this->collectFiles($job->dir('site'));
            Util::writeJson($listFile, $files);
            $job->meta['pack_total'] = count($files);
            $job->meta['pack_at'] = 0;
            $job->log('info', 'Compressing ' . count($files) . ' file(s)');
        }
        $files = Util::readJson($listFile, []);
        $total = count($files);
        $at = (int) ($job->meta['pack_at'] ?? 0);

        $zipPath = $job->dir('out/' . $this->zipName());
        $folder = $this->folderName();

        $zip = $at === 0 || !is_file($zipPath)
            ? ZipWriter::create($zipPath, 6)
            : ZipWriter::resume($zipPath, (array) ($job->meta['zip_state'] ?? []), 6);

        try {
            while ($at < $total && microtime(true) < $deadline) {
                $rel = (string) $files[$at];
                $at++;
                if ($zip->nearLimits()) {
                    $job->note('Archive hit the ZIP format limit; some files were left out.');
                    $at = $total;
                    break;
                }
                $zip->addFile($job->dir('site/' . $rel), $folder . '/' . $rel);
            }

            if ($at >= $total) {
                $size = $zip->finish();

                $job->meta['zip'] = [
                    'name'  => basename($zipPath),
                    'size'  => $size,
                    'size_h'=> Util::bytes($size),
                    'files' => $zip->count(),
                ];
                $job->meta['zip_state'] = null;
                $job->meta['pack_at'] = $at;
                $job->finish('done');
                $job->log('done', 'ZIP ready: ' . basename($zipPath) . ' (' . Util::bytes($size) . ', '
                    . $zip->count() . ' files)');
                return;
            }

            $job->meta['zip_state'] = $zip->state();
            $job->meta['pack_at'] = $at;
            $zip->close();
            $job->log('muted', '   compressed ' . $at . ' / ' . $total);
        } catch (Throwable $e) {
            $zip->close();
            throw $e;
        }
    }

    /** @return string[] site-relative file paths */
    private function collectFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        $prefix = strlen(Util::slashes($dir)) + 1;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            /** @var SplFileInfo $f */
            if ($f->isFile()) {
                $out[] = substr(Util::slashes($f->getPathname()), $prefix);
            }
        }
        sort($out);
        return $out;
    }

    private function folderName(): string
    {
        $host = (string) ($this->job->meta['host'] ?? 'site');
        return Util::safeSegment($host !== '' ? $host : 'site');
    }

    private function zipName(): string
    {
        return $this->folderName() . '-' . date('Ymd-His', (int) $this->job->meta['created']) . '.zip';
    }

    /* ------------------------------------------------------------------ bundled docs */

    /**
     * Report, manifest and README go inside site/_sitegrabber/ so they are both
     * part of the archive and viewable in the browser straight away.
     */
    private function writeDocs(): void
    {
        $job = $this->job;
        $dir = $job->dir('site/_sitegrabber');
        Util::ensureDir($dir);

        // Remember which file the "Open the copy" button should point at.
        $entry = (string) ($job->res[(string) $job->meta['root_url']]['p'] ?? '');
        if ($entry === '') {
            foreach ($job->res as $rec) {
                if (($rec['st'] ?? '') === 'ok' && ($rec['t'] ?? '') === 'html' && ($rec['p'] ?? '') !== '') {
                    $entry = (string) $rec['p'];
                    break;
                }
            }
        }
        $job->meta['entry'] = $entry;

        @file_put_contents($dir . '/report.html', $this->report());
        @file_put_contents($dir . '/manifest.json', $this->manifest());
        @file_put_contents($dir . '/README.txt', $this->readme());
    }

    private function manifest(): string
    {
        $job = $this->job;
        $rows = [];
        foreach ($job->res as $url => $r) {
            $rows[] = [
                'url'    => $url,
                'file'   => $r['p'] ?? '',
                'type'   => $r['t'] ?? '',
                'kind'   => $r['k'] ?? '',
                'status' => $r['c'] ?? 0,
                'bytes'  => $r['z'] ?? 0,
                'state'  => $r['st'] ?? '',
                'note'   => $r['e'] ?? '',
            ];
        }
        return (string) json_encode([
            'tool'      => sg_config('app_name') . ' ' . sg_config('version'),
            'site'      => $job->meta['root_url'],
            'host'      => $job->meta['host'],
            'captured'  => date('c', (int) $job->meta['created']),
            'options'   => $job->meta['options'],
            'server'    => $job->meta['server'],
            'counters'  => $job->meta['counters'],
            'notes'     => $job->meta['truncated'],
            'resources' => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function readme(): string
    {
        $job = $this->job;
        $c = $job->meta['counters'];
        $dynamic = (int) ($job->meta['dynamic'] ?? 0);
        $server = $job->meta['server'] ?? [];

        $lines = [];
        $lines[] = 'OFFLINE COPY OF ' . strtoupper((string) $job->meta['host']);
        $lines[] = str_repeat('=', 60);
        $lines[] = '';
        $lines[] = 'Captured : ' . date('Y-m-d H:i:s', (int) $job->meta['created']);
        $lines[] = 'Source   : ' . $job->meta['root_url'];
        $lines[] = 'Tool     : ' . sg_config('app_name') . ' ' . sg_config('version');
        $lines[] = '';
        $lines[] = 'WHAT IS IN HERE';
        $lines[] = '---------------';
        $lines[] = 'Pages (HTML)      : ' . (int) $c['pages_done'];
        $lines[] = 'Other files       : ' . (int) $c['assets_done'] . '  (css, js, images, fonts, ...)';
        $lines[] = 'Failed            : ' . ((int) $c['pages_failed'] + (int) $c['assets_failed']);
        $lines[] = 'Skipped           : ' . (int) $c['skipped'];
        $lines[] = 'Total size        : ' . Util::bytes((int) $c['bytes']);
        $lines[] = '';
        $lines[] = 'Open index.html (or any .html file) in a browser. Every link, stylesheet,';
        $lines[] = 'script and image has been rewritten to point at the files in this folder,';
        $lines[] = 'so it works with no internet connection.';
        $lines[] = '';
        $lines[] = 'Open _sitegrabber/report.html for a full list of everything downloaded.';
        $lines[] = '';
        $lines[] = 'WHY THERE IS NO PHP SOURCE OR DATABASE IN HERE';
        $lines[] = '----------------------------------------------';
        $lines[] = 'This is the single most common misunderstanding about site downloaders,';
        $lines[] = 'so it is worth stating plainly:';
        $lines[] = '';
        $lines[] = 'A web server EXECUTES PHP and sends back only the finished HTML. The';
        $lines[] = 'PHP source code and the database never leave the server. When your';
        $lines[] = 'browser (or this tool) requests /product.php, the server runs that file,';
        $lines[] = 'queries MySQL, and returns HTML. The .php file itself is never sent.';
        $lines[] = '';
        $lines[] = 'So no downloader - not this one, not HTTrack, not wget, not any browser -';
        $lines[] = 'can retrieve .php source or a database from a live website. The only ways';
        $lines[] = 'to get those are legitimate access to the server itself: FTP/SFTP, cPanel,';
        $lines[] = 'SSH, a Git repository, phpMyAdmin, or a backup the owner gives you.';
        $lines[] = '';
        if ($dynamic > 0) {
            $lines[] = 'This site served ' . $dynamic . ' page(s) from a server-side script';
            $lines[] = '(.php/.asp/.jsp and similar). They are saved here as the HTML they';
            $lines[] = 'produced, with .html added to the file name.';
            $lines[] = '';
        }
        if ($server) {
            $lines[] = 'Server fingerprint (from the HTTP response headers):';
            foreach ($server as $k => $v) {
                if ($v !== '') {
                    $lines[] = '  ' . str_pad((string) $k, 14) . ': ' . $v;
                }
            }
            $lines[] = '';
        }
        $lines[] = 'IF YOU OWN THIS SITE and it is hosted on this computer, use the';
        $lines[] = '"Local project" tab in SiteGrabber instead. That reads the real .php';
        $lines[] = 'files from disk and exports a real MySQL dump (.sql).';
        $lines[] = '';
        if (!empty($job->meta['truncated'])) {
            $lines[] = 'LIMITS HIT DURING THIS CAPTURE';
            $lines[] = '------------------------------';
            foreach ($job->meta['truncated'] as $n) {
                $lines[] = ' - ' . $n;
            }
            $lines[] = '';
        }
        $lines[] = 'Respect copyright and the site owner\'s terms when reusing this content.';
        $lines[] = '';
        return implode("\r\n", $lines);
    }

    private function report(): string
    {
        $job = $this->job;
        $c = $job->meta['counters'];
        $e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        $rows = '';
        $shown = 0;
        foreach ($job->res as $url => $r) {
            if ($shown >= 4000) {
                break;
            }
            $shown++;
            $state = $r['st'] ?? '';
            $file = (string) ($r['p'] ?? '');
            $link = $file !== '' && $state === 'ok'
                ? '<a href="../' . $e($file) . '">' . $e($file) . '</a>'
                : '<span class="dim">' . $e($r['e'] ?? '-') . '</span>';
            $rows .= '<tr class="s-' . $e($state) . '" data-state="' . $e($state) . '" data-type="' . $e($r['t'] ?? '') . '">'
                . '<td><span class="pill p-' . $e($state) . '">' . $e($state) . '</span></td>'
                . '<td class="num">' . $e($r['c'] ?? '') . '</td>'
                . '<td><span class="pill p-type">' . $e($r['t'] ?? '') . '</span></td>'
                . '<td class="num">' . $e(Util::bytes((int) ($r['z'] ?? 0))) . '</td>'
                . '<td class="url" title="' . $e($url) . '">' . $e($url) . '</td>'
                . '<td>' . $link . '</td>'
                . '</tr>';
        }

        $notes = '';
        foreach ($job->meta['truncated'] ?? [] as $n) {
            $notes .= '<li>' . $e($n) . '</li>';
        }
        $notesBlock = $notes !== '' ? '<div class="warn"><strong>Limits reached</strong><ul>' . $notes . '</ul></div>' : '';

        $serverRows = '';
        foreach ($job->meta['server'] ?? [] as $k => $v) {
            if ($v !== '') {
                $serverRows .= '<tr><th>' . $e($k) . '</th><td>' . $e($v) . '</td></tr>';
            }
        }

        $stat = static fn($label, $value) =>
            '<div class="stat"><div class="sv">' . $value . '</div><div class="sl">' . $label . '</div></div>';

        return '<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Capture report - ' . $e($job->meta['host']) . '</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#0b0f17;color:#e6edf6;font:14px/1.6 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;padding:32px}
.wrap{max-width:1200px;margin:0 auto}
h1{font-size:24px;margin:0 0 4px}
h2{font-size:16px;margin:32px 0 12px;color:#9fb3cd;text-transform:uppercase;letter-spacing:.08em}
a{color:#5eb3ff}
.sub{color:#8ea3bd;margin-bottom:24px;word-break:break-all}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:8px}
.stat{background:#131a26;border:1px solid #1f2a3a;border-radius:12px;padding:14px 16px}
.sv{font-size:22px;font-weight:650;color:#fff}
.sl{font-size:11px;color:#8ea3bd;text-transform:uppercase;letter-spacing:.07em;margin-top:2px}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{text-align:left;padding:7px 10px;border-bottom:1px solid #1b2433;vertical-align:top}
th{color:#9fb3cd;font-weight:600;position:sticky;top:0;background:#0b0f17}
.num{text-align:right;white-space:nowrap;color:#9fb3cd}
.url{word-break:break-all;max-width:460px;font-family:ui-monospace,Consolas,monospace;font-size:12px}
.pill{display:inline-block;padding:1px 8px;border-radius:999px;font-size:11px;font-weight:600}
.p-ok{background:#0d3321;color:#4ade80}.p-fail{background:#3a1218;color:#f87171}
.p-skip{background:#332a0d;color:#fbbf24}.p-queued{background:#1b2433;color:#8ea3bd}
.p-type{background:#15233a;color:#7dd3fc}
.dim{color:#6b7f99}
.warn{background:#2a1f08;border:1px solid #5a4410;border-radius:12px;padding:12px 18px;margin:16px 0;color:#fcd34d}
.warn ul{margin:6px 0 0 18px}
.info{background:#0f1a2b;border:1px solid #1f3a5f;border-radius:12px;padding:16px 20px;margin:16px 0}
.note{background:#0f1a2b;border:1px solid #223a52;border-radius:12px;padding:16px 20px;margin:16px 0;color:#bcd0e8}
.filters{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}
.filters button{background:#131a26;border:1px solid #243247;color:#cfe0f5;padding:6px 14px;border-radius:999px;cursor:pointer;font-size:12px}
.filters button.on{background:#1d4ed8;border-color:#1d4ed8;color:#fff}
kbd{background:#1b2433;border-radius:4px;padding:1px 6px;font-size:12px}
.tablewrap{max-height:70vh;overflow:auto;border:1px solid #1f2a3a;border-radius:12px}
</style></head><body><div class="wrap">
<h1>Capture report</h1>
<div class="sub">' . $e($job->meta['root_url']) . ' &middot; ' . date('j M Y, H:i', (int) $job->meta['created']) . '</div>
<div class="stats">'
            . $stat('Pages', (string) (int) $c['pages_done'])
            . $stat('Files', (string) (int) $c['assets_done'])
            . $stat('Failed', (string) ((int) $c['pages_failed'] + (int) $c['assets_failed']))
            . $stat('Skipped', (string) (int) $c['skipped'])
            . $stat('Total size', Util::bytes((int) $c['bytes']))
            . $stat('Requests', (string) (int) ($c['requests'] ?? 0))
            . '</div>'
            . $notesBlock
            . '<div class="note"><strong>No PHP source or database here &mdash; and that is not a bug.</strong><br>
A server runs PHP and sends back only HTML, so the <code>.php</code> files and the database
never travel over HTTP. See <code>README.txt</code> next to this file for the full explanation
and for the legitimate ways to obtain them.</div>'
            . ($serverRows !== '' ? '<h2>Server fingerprint</h2><table>' . $serverRows . '</table>' : '')
            . '<h2>Every URL we touched (' . $shown . ')</h2>
<div class="filters">
  <button class="on" data-f="all">All</button>
  <button data-f="ok">Downloaded</button>
  <button data-f="fail">Failed</button>
  <button data-f="skip">Skipped</button>
</div>
<div class="tablewrap"><table>
<thead><tr><th>State</th><th class="num">HTTP</th><th>Type</th><th class="num">Size</th><th>URL</th><th>Saved as</th></tr></thead>
<tbody>' . $rows . '</tbody></table></div>
</div>
<script>
document.querySelectorAll(".filters button").forEach(function(b){
  b.addEventListener("click",function(){
    document.querySelectorAll(".filters button").forEach(function(x){x.classList.remove("on")});
    b.classList.add("on");
    var f=b.dataset.f;
    document.querySelectorAll("tbody tr").forEach(function(tr){
      tr.style.display=(f==="all"||tr.dataset.state===f)?"":"none";
    });
  });
});
</script>
</body></html>';
    }
}
