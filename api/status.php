<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

$job = sg_job();
sg_json(['ok' => true, 'snapshot' => $job->snapshot(sg_int('log_at', 0))]);
