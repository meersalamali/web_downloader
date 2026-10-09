<?php
declare(strict_types=1);

/**
 * One download job, stored as plain files under storage/jobs/<id>/.
 *
 *   job.json    status, options, counters
 *   queue.json  URLs still to fetch
 *   res.json    every URL we have seen, with its local path and outcome
 *   log.jsonl   append-only activity log streamed to the browser
 *   raw/        bytes exactly as downloaded
 *   site/       rewritten, offline-ready copy
 *   out/        the finished .zip
 */
final class Job
{
    public const PHASE_INIT    = 'init';
    public const PHASE_CRAWL   = 'crawl';
    public const PHASE_REWRITE = 'rewrite';
    public const PHASE_PACKAGE = 'package';
    public const PHASE_DONE    = 'done';

    public string $id;
    public array $meta;
    /** @var array<int,array> queue rows: [url, depth, kind, type] */
    public array $queue = [];
    /** @var array<string,array> url => resource record */
    public array $res = [];

    private bool $queueLoaded = false;
    private bool $resLoaded = false;
    /** @var resource|null */
    private $lock = null;

    private function __construct(string $id, array $meta)
    {
        $this->id = $id;
        $this->meta = $meta;
    }

    public function dir(string $sub = ''): string
    {
        $d = sg_storage('jobs/' . $this->id);
        return $sub === '' ? $d : $d . '/' . ltrim($sub, '/');
    }

    /* ---------------------------------------------------------------- create / load */

    public static function create(array $options, string $rootUrl, string $type = 'crawl'): self
    {
        $id = Util::uid();
        $host = Url::host($rootUrl);

        $meta = [
            'id'        => $id,
            'type'      => $type,
            'status'    => 'running',
            'phase'     => self::PHASE_INIT,
            'root_url'  => $rootUrl,
            'host'      => $host,
            'title'     => $host !== '' ? $host : $rootUrl,
            'options'   => $options,
            'robots'    => null,
            'created'   => time(),
            'updated'   => time(),
            'finished'  => null,
            'counters'  => [
                'pages_done'   => 0,
                'pages_failed' => 0,
                'assets_done'  => 0,
                'assets_failed'=> 0,
                'skipped'      => 0,
                'bytes'        => 0,
                'requests'     => 0,
            ],
            'rewrite_at' => 0,
            'pack_at'    => 0,
            'zip_state'  => null,
            'zip'        => null,
            'error'      => null,
            'truncated'  => [],
            'server'     => [],
            'dynamic'    => 0,
        ];

        $job = new self($id, $meta);
        Util::ensureDir($job->dir('raw'));
        Util::ensureDir($job->dir('site'));
        Util::ensureDir($job->dir('out'));

        // Keep the job's own folders from being browsable. site/ is left alone:
        // it holds the copied website and is meant to be opened.
        Util::denyListing($job->dir());
        Util::denyListing($job->dir('raw'));
        Util::denyListing($job->dir('out'));
        $job->queueLoaded = true;
        $job->resLoaded = true;
        $job->save();
        return $job;
    }

    public static function load(string $id): self
    {
        if (!preg_match('/^[0-9]{8}-[0-9]{6}-[0-9a-f]{6}$/', $id)) {
            throw new RuntimeException('Bad job id');
        }
        $file = sg_storage('jobs/' . $id . '/job.json');
        if (!is_file($file)) {
            throw new RuntimeException('Job not found: ' . $id);
        }
        $meta = Util::readJson($file);
        if (!$meta) {
            throw new RuntimeException('Job data unreadable: ' . $id);
        }
        return new self($id, $meta);
    }

    public static function exists(string $id): bool
    {
        return preg_match('/^[0-9]{8}-[0-9]{6}-[0-9a-f]{6}$/', $id) === 1
            && is_file(sg_storage('jobs/' . $id . '/job.json'));
    }

    /** Recent jobs, newest first. */
    public static function all(int $limit = 40): array
    {
        $out = [];
        foreach (glob(sg_storage('jobs') . '/*/job.json') ?: [] as $f) {
            $m = Util::readJson($f);
            if (!$m || empty($m['id'])) {
                continue;
            }
            $out[] = [
                'id'       => $m['id'],
                'type'     => $m['type'] ?? 'crawl',
                'title'    => $m['title'] ?? '',
                'root_url' => $m['root_url'] ?? '',
                'status'   => $m['status'] ?? '',
                'phase'    => $m['phase'] ?? '',
                'created'  => $m['created'] ?? 0,
                'counters' => $m['counters'] ?? [],
                'zip'      => $m['zip'] ?? null,
            ];
        }
        usort($out, static fn($a, $b) => ($b['created'] ?? 0) <=> ($a['created'] ?? 0));
        return array_slice($out, 0, $limit);
    }

    public static function remove(string $id): bool
    {
        if (!self::exists($id)) {
            return false;
        }
        Util::rrmdir(sg_storage('jobs/' . $id));
        return true;
    }

    /* ---------------------------------------------------------------- persistence */

    public function save(): void
    {
        $this->meta['updated'] = time();
        Util::writeJson($this->dir('job.json'), $this->meta);
        if ($this->queueLoaded) {
            Util::writeJson($this->dir('queue.json'), $this->queue);
        }
        if ($this->resLoaded) {
            Util::writeJson($this->dir('res.json'), $this->res);
        }
    }

    public function loadQueue(): void
    {
        if (!$this->queueLoaded) {
            $this->queue = Util::readJson($this->dir('queue.json'), []);
            $this->queueLoaded = true;
        }
    }

    public function loadRes(): void
    {
        if (!$this->resLoaded) {
            $this->res = Util::readJson($this->dir('res.json'), []);
            $this->resLoaded = true;
        }
    }

    /** Only one tick may work on a job at a time. */
    public function acquireLock(): bool
    {
        $fh = @fopen($this->dir('lock'), 'c');
        if (!$fh) {
            return true; // cannot lock: let the tick proceed rather than stall forever
        }
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            return false;
        }
        $this->lock = $fh;
        return true;
    }

    public function releaseLock(): void
    {
        if (is_resource($this->lock)) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
    }

    /* ---------------------------------------------------------------- queue + resources */

    public function seen(string $url): bool
    {
        return isset($this->res[$url]);
    }

    /** Register a URL and push it on the queue. Returns false if already known. */
    public function enqueue(string $url, int $depth, string $kind, string $type, string $from = ''): bool
    {
        if ($url === '' || isset($this->res[$url])) {
            return false;
        }
        $this->res[$url] = [
            'k' => $kind, 't' => $type, 'st' => 'queued', 'd' => $depth,
            'p' => '', 'c' => 0, 'z' => 0, 'e' => '', 'f' => $from,
        ];
        $this->queue[] = [$url, $depth, $kind, $type];
        return true;
    }

    /**
     * Take up to $n rows off the queue, pages first so breadth-first order is kept
     * and the page budget is spent on real pages rather than images.
     */
    public function takeBatch(int $n): array
    {
        $batch = [];
        $rest = [];
        foreach ($this->queue as $row) {
            if (count($batch) < $n && ($row[2] ?? '') === 'page') {
                $batch[] = $row;
            } else {
                $rest[] = $row;
            }
        }
        if (count($batch) < $n) {
            $keep = [];
            foreach ($rest as $row) {
                if (count($batch) < $n) {
                    $batch[] = $row;
                } else {
                    $keep[] = $row;
                }
            }
            $rest = $keep;
        }
        $this->queue = $rest;
        return $batch;
    }

    public function queueSize(): int
    {
        return count($this->queue);
    }

    public function markDone(string $url, array $patch): void
    {
        $this->res[$url] = ($this->res[$url] ?? []) + ['k' => 'asset', 't' => '', 'd' => 0];
        foreach ($patch as $k => $v) {
            $this->res[$url][$k] = $v;
        }
    }

    public function bump(string $counter, int $by = 1): void
    {
        $this->meta['counters'][$counter] = ($this->meta['counters'][$counter] ?? 0) + $by;
    }

    public function counter(string $name): int
    {
        return (int) ($this->meta['counters'][$name] ?? 0);
    }

    public function opt(string $name, $default = null)
    {
        return $this->meta['options'][$name] ?? $default;
    }

    public function note(string $text): void
    {
        $this->meta['truncated'][] = $text;
        $this->meta['truncated'] = array_values(array_unique($this->meta['truncated']));
    }

    /* ---------------------------------------------------------------- log */

    public function log(string $level, string $msg, array $extra = []): void
    {
        $row = ['t' => round(microtime(true), 2), 'l' => $level, 'm' => $msg] + $extra;
        $line = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($line === false) {
            return;
        }
        @file_put_contents($this->dir('log.jsonl'), $line . "\n", FILE_APPEND);
    }

    /**
     * Read log lines written after byte offset $from.
     * @return array{lines:array,offset:int}
     */
    public function logSince(int $from): array
    {
        $file = $this->dir('log.jsonl');
        if (!is_file($file)) {
            return ['lines' => [], 'offset' => 0];
        }
        $size = (int) filesize($file);
        if ($from >= $size) {
            return ['lines' => [], 'offset' => $size];
        }
        // A client that fell far behind only gets the tail.
        if ($size - $from > 400000) {
            $from = $size - 400000;
        }
        $fh = @fopen($file, 'rb');
        if (!$fh) {
            return ['lines' => [], 'offset' => $size];
        }
        fseek($fh, $from);
        $data = (string) stream_get_contents($fh);
        fclose($fh);

        // Keep only whole lines; a half-written tail is left for the next poll.
        $lastNl = strrpos($data, "\n");
        if ($lastNl === false) {
            return ['lines' => [], 'offset' => $from];
        }
        $whole = substr($data, 0, $lastNl + 1);
        $consumed = $from + strlen($whole);

        $lines = [];
        foreach (explode("\n", $whole) as $raw) {
            if ($raw === '') {
                continue;
            }
            $row = json_decode($raw, true);
            if (is_array($row)) {
                $lines[] = $row;
            }
        }
        return ['lines' => array_slice($lines, -400), 'offset' => $consumed];
    }

    /* ---------------------------------------------------------------- status */

    public function status(): string
    {
        return (string) ($this->meta['status'] ?? 'running');
    }

    public function phase(): string
    {
        return (string) ($this->meta['phase'] ?? self::PHASE_INIT);
    }

    public function setPhase(string $phase): void
    {
        $this->meta['phase'] = $phase;
    }

    public function finish(string $status, ?string $error = null): void
    {
        $this->meta['status'] = $status;
        $this->meta['phase'] = self::PHASE_DONE;
        $this->meta['finished'] = time();
        if ($error !== null) {
            $this->meta['error'] = $error;
        }
    }

    public function isRunning(): bool
    {
        return $this->status() === 'running';
    }

    /** Rough progress: crawling is most of the work, then rewrite, then zip. */
    public function progress(): int
    {
        $c = $this->meta['counters'];
        $done = (int) $c['pages_done'] + (int) $c['pages_failed']
              + (int) $c['assets_done'] + (int) $c['assets_failed'];
        $total = max(1, $done + $this->queueSize());

        return match ($this->phase()) {
            self::PHASE_INIT    => 2,
            self::PHASE_CRAWL   => (int) min(78, 3 + round(75 * $done / $total)),
            self::PHASE_REWRITE => 80 + (int) min(12, round(12 * $this->rewriteFraction())),
            self::PHASE_PACKAGE => 93 + (int) min(6, round(6 * $this->packFraction())),
            default             => 100,
        };
    }

    private function rewriteFraction(): float
    {
        $total = max(1, count($this->res));
        return min(1.0, (int) ($this->meta['rewrite_at'] ?? 0) / $total);
    }

    private function packFraction(): float
    {
        $total = max(1, (int) ($this->meta['pack_total'] ?? count($this->res)));
        return min(1.0, (int) ($this->meta['pack_at'] ?? 0) / $total);
    }

    /** Snapshot for the UI. */
    public function snapshot(int $logFrom = 0): array
    {
        $this->loadQueue();
        // A negative offset means "skip the log": work ticks do not need it,
        // only the lightweight status poll streams log lines to the browser.
        $log = $logFrom < 0 ? ['lines' => [], 'offset' => -1] : $this->logSince($logFrom);
        $c = $this->meta['counters'];

        return [
            'id'        => $this->id,
            'type'      => $this->meta['type'] ?? 'crawl',
            'status'    => $this->status(),
            'phase'     => $this->phase(),
            'progress'  => $this->progress(),
            'title'     => $this->meta['title'] ?? '',
            'root_url'  => $this->meta['root_url'] ?? '',
            'counters'  => $c,
            'queue'     => $this->queueSize(),
            'bytes_h'   => Util::bytes((int) ($c['bytes'] ?? 0)),
            'elapsed'   => max(0, (int) (($this->meta['finished'] ?? time()) - ($this->meta['created'] ?? time()))),
            'error'     => $this->meta['error'] ?? null,
            'notes'     => array_values($this->meta['truncated'] ?? []),
            'server'    => $this->meta['server'] ?? [],
            'dynamic'   => (int) ($this->meta['dynamic'] ?? 0),
            'zip'       => $this->meta['zip'] ?? null,
            'entry'     => $this->meta['entry'] ?? null,
            'log'       => $log['lines'],
            'log_at'    => $log['offset'],
            'done'      => in_array($this->status(), ['done', 'error', 'cancelled'], true),
        ];
    }
}
