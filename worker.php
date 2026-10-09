<?php
declare(strict_types=1);

/**
 * Command line runner. Useful for big sites, where you would rather not keep a
 * browser tab open, and for scheduling captures.
 *
 *   php worker.php --url=https://example.com
 *   php worker.php --url=example.com --pages=800 --depth=12 --media
 *   php worker.php --job=20261009-120501-ab12cd        (resume an existing job)
 *   php worker.php --list
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("worker.php runs from the command line only.\n");
}

require __DIR__ . '/bootstrap.php';

$argvOpts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z0-9_-]+)(?:=(.*))?$/i', $arg, $m)) {
        $argvOpts[strtolower($m[1])] = $m[2] ?? '1';
    }
}

$get = static fn(string $k, $d = null) => $argvOpts[$k] ?? $d;
$has = static fn(string $k) => array_key_exists($k, $argvOpts);

/* ---------------------------------------------------------------- help / list */

if ($has('help') || $argvOpts === []) {
    echo <<<TXT

  SiteGrabber worker
  ------------------

  php worker.php --url=<address> [options]
  php worker.php --job=<job id>          resume or finish an existing job
  php worker.php --list                  show recent jobs

  Options
    --pages=N        max pages           (default 150)
    --depth=N        max link depth      (default 6)
    --files=N        max asset files     (default 1500)
    --total=N        max total MB        (default 600)
    --filemb=N       max MB per file     (default 25)
    --parallel=N     parallel downloads  (default 5)
    --delay=N        ms between batches  (default 150)
    --subdomains     include subdomains
    --media          include video and audio
    --no-external    skip files on other domains
    --no-robots      ignore robots.txt   (use only on sites you control)
    --no-sitemap     do not read sitemap.xml
    --scan-js        look for asset paths inside JavaScript
    --cookie="..."   send a Cookie header, for logged-in pages
    --quiet          only print the final result

TXT;
    exit(0);
}

if ($has('list')) {
    $rows = Job::all(25);
    if (!$rows) {
        echo "No jobs yet.\n";
        exit(0);
    }
    printf("%-26s %-9s %-8s %7s %7s  %s\n", 'JOB ID', 'STATUS', 'PHASE', 'PAGES', 'FILES', 'SITE');
    foreach ($rows as $j) {
        printf(
            "%-26s %-9s %-8s %7d %7d  %s\n",
            $j['id'],
            $j['status'],
            $j['phase'],
            (int) ($j['counters']['pages_done'] ?? 0),
            (int) ($j['counters']['assets_done'] ?? 0),
            $j['title']
        );
    }
    exit(0);
}

/* ---------------------------------------------------------------- build the job */

$quiet = $has('quiet');

if ($has('job')) {
    $job = Job::load((string) $get('job'));
    if (!$job->isRunning()) {
        echo "Job {$job->id} is already finished (status: {$job->status()}).\n";
        exit(0);
    }
    echo "Resuming job {$job->id} - {$job->meta['title']}\n";
} else {
    $url = trim((string) $get('url', ''));
    if ($url === '') {
        exit("Nothing to do: pass --url=https://example.com (or --help).\n");
    }
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . ltrim($url, '/');
    }
    $url = Url::normalize($url);
    if ($url === '' || Url::host($url) === '') {
        exit("That address does not look valid.\n");
    }

    $def  = (array) sg_config('defaults');
    $hard = (array) sg_config('hard_limits');

    $options = [
        'max_pages'          => (int) Util::clamp((int) $get('pages', $def['max_pages']), 1, $hard['max_pages']),
        'max_assets'         => (int) Util::clamp((int) $get('files', $def['max_assets']), 0, $hard['max_assets']),
        'max_depth'          => (int) Util::clamp((int) $get('depth', $def['max_depth']), 0, $hard['max_depth']),
        'max_file_mb'        => (int) Util::clamp((int) $get('filemb', $def['max_file_mb']), 1, $hard['max_file_mb']),
        'max_total_mb'       => (int) Util::clamp((int) $get('total', $def['max_total_mb']), 1, $hard['max_total_mb']),
        'concurrency'        => (int) Util::clamp((int) $get('parallel', $def['concurrency']), 1, $hard['concurrency']),
        'delay_ms'           => (int) Util::clamp((int) $get('delay', $def['delay_ms']), 0, 10000),
        'timeout'            => (int) $def['timeout'],
        'respect_robots'     => !$has('no-robots'),
        'include_subdomains' => $has('subdomains'),
        'external_assets'    => !$has('no-external'),
        'use_sitemap'        => !$has('no-sitemap'),
        'scan_js'            => $has('scan-js'),
        'strip_integrity'    => true,
        'keep_absolute'      => true,
        'verify_ssl'         => $has('verify-ssl'),
        'user_agent'         => (string) $get('agent', ''),
        'cookie'             => (string) $get('cookie', ''),
        'auth_user'          => (string) $get('user', ''),
        'auth_pass'          => (string) $get('pass', ''),
        'skip_ext'           => (string) $get('skip', ''),
        'types'              => [
            'css'   => true,
            'js'    => true,
            'img'   => true,
            'font'  => true,
            'media' => $has('media'),
            'doc'   => true,
        ],
    ];

    $job = Job::create($options, $url, 'crawl');
    echo "Job {$job->id} created for {$url}\n";
    if (!$options['respect_robots']) {
        echo "NOTE: robots.txt is being ignored. Only do this on sites you control.\n";
    }
}

/* ---------------------------------------------------------------- run to completion */

$logAt = 0;
$lastPhase = '';
$start = microtime(true);

while (true) {
    $job = Job::load($job->id);
    $snap = ($job->meta['type'] ?? 'crawl') === 'local'
        ? (new LocalExport($job))->tick($logAt)
        : (new Crawler($job))->tick($logAt);

    if (!$quiet) {
        foreach ($snap['log'] as $line) {
            $tag = str_pad(strtoupper((string) ($line['l'] ?? '')), 5);
            echo '  ' . $tag . ' ' . ($line['m'] ?? '') . "\n";
        }
        if (($snap['phase'] ?? '') !== $lastPhase) {
            $lastPhase = (string) $snap['phase'];
        }
    }
    if ($snap['log_at'] >= 0) {
        $logAt = (int) $snap['log_at'];
    }

    if (!empty($snap['done'])) {
        break;
    }
    if (!empty($snap['busy'])) {
        usleep(400000);
    }
}

$elapsed = round(microtime(true) - $start, 1);
$c = $snap['counters'];

echo "\n";
echo "Status      : " . $snap['status'] . "\n";
echo "Pages       : " . (int) $c['pages_done'] . "\n";
echo "Other files : " . (int) $c['assets_done'] . "\n";
echo "Failed      : " . ((int) $c['pages_failed'] + (int) $c['assets_failed']) . "\n";
echo "Downloaded  : " . $snap['bytes_h'] . "\n";
echo "Took        : " . $elapsed . "s\n";

if (!empty($snap['zip'])) {
    $path = sg_storage('jobs/' . $job->id . '/out/' . $snap['zip']['name']);
    echo "Archive     : " . $path . " (" . $snap['zip']['size_h'] . ")\n";
}
foreach ($snap['notes'] as $note) {
    echo "Note        : " . $note . "\n";
}
if (!empty($snap['error'])) {
    echo "Error       : " . $snap['error'] . "\n";
    exit(1);
}
exit(0);
