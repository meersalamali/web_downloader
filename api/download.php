<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

/**
 * Streams the finished archive. Deliberately not using _boot.php, because this
 * endpoint sends binary data rather than JSON.
 */

$fail = static function (string $msg, int $code = 404): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
};

$id = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
if ($id === '' || !Job::exists($id)) {
    $fail('That download is no longer available.');
}

$job = Job::load($id);
$zip = $job->meta['zip'] ?? null;
if (!is_array($zip) || ($zip['name'] ?? '') === '') {
    $fail('This job has no archive yet. Wait for it to finish.');
}

// basename() keeps a crafted id or name from escaping the job folder.
$name = basename((string) $zip['name']);
$file = $job->dir('out/' . $name);
if (!is_file($file)) {
    $fail('The archive file is missing from storage.');
}

$size = (int) filesize($file);

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Content-Length: ' . $size);
header('Content-Transfer-Encoding: binary');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Accept-Ranges: none');

$fh = fopen($file, 'rb');
if (!$fh) {
    $fail('Cannot read the archive file.', 500);
}
while (!feof($fh)) {
    $chunk = fread($fh, 262144);
    if ($chunk === false) {
        break;
    }
    echo $chunk;
    flush();
}
fclose($fh);
