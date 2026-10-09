<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

$id = sg_str('id');
if ($id === '') {
    sg_fail('No job id was sent.');
}
if (!Job::exists($id)) {
    sg_json(['ok' => true, 'deleted' => false]);
}
Job::remove($id);
sg_json(['ok' => true, 'deleted' => true]);
