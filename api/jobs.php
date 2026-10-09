<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

$rows = [];
foreach (Job::all(40) as $j) {
    $c = $j['counters'] ?? [];
    $rows[] = [
        'id'      => $j['id'],
        'type'    => $j['type'],
        'title'   => $j['title'],
        'url'     => $j['root_url'],
        'status'  => $j['status'],
        'when'    => (int) $j['created'],
        'when_h'  => date('j M Y, H:i', (int) $j['created']),
        'pages'   => (int) ($c['pages_done'] ?? 0),
        'files'   => (int) ($c['assets_done'] ?? 0),
        'bytes_h' => Util::bytes((int) ($c['bytes'] ?? 0)),
        'zip'     => $j['zip'],
    ];
}
sg_json(['ok' => true, 'jobs' => $rows]);
