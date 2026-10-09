<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

$db = LocalExport::listDatabases();
sg_json([
    'ok'        => true,
    'projects'  => LocalExport::listProjects(),
    'databases' => $db['databases'],
    'db_error'  => $db['error'],
    'roots'     => (array) (sg_config('local_export')['roots'] ?? []),
]);
