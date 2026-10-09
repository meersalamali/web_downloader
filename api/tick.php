<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

$job = sg_job();
$logAt = sg_int('log_at', 0);

$snapshot = ($job->meta['type'] ?? 'crawl') === 'local'
    ? (new LocalExport($job))->tick($logAt)
    : (new Crawler($job))->tick($logAt);

sg_json(['ok' => true, 'snapshot' => $snapshot]);
