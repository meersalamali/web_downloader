<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

$path = sg_str('path');
if ($path === '') {
    sg_fail('Please choose a project folder.');
}
$path = LocalExport::validatePath($path);

$dbs = sg_input()['databases'] ?? [];
if (is_string($dbs)) {
    $dbs = array_filter(array_map('trim', explode(',', $dbs)));
}
$dbs = array_values(array_filter(array_map('strval', (array) $dbs)));

$options = [
    'local_path'  => $path,
    'databases'   => $dbs,
    'skip_dirs'   => sg_str('skip_dirs', ''),
    'max_file_mb' => (int) Util::clamp(sg_int('max_file_mb', 50), 1, 500),
];

$job = Job::create($options, 'file:///' . $path, 'local');
$job->meta['title'] = basename($path);
$job->save();

sg_json(['ok' => true, 'id' => $job->id, 'snapshot' => $job->snapshot(0)]);
