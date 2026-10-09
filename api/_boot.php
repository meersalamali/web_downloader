<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

/** Never let a PHP notice corrupt the JSON body. */
ini_set('display_errors', '0');
error_reporting(E_ALL);

set_exception_handler(static function (Throwable $e): void {
    sg_json(['ok' => false, 'error' => $e->getMessage()], 500);
});

set_error_handler(static function (int $no, string $str, string $file = '', int $line = 0): bool {
    if ((error_reporting() & $no) === 0) {
        return true;
    }
    throw new ErrorException($str, 0, $no, $file, $line);
});

function sg_json($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function sg_fail(string $message, int $code = 400): void
{
    sg_json(['ok' => false, 'error' => $message], $code);
}

/** Request payload, whether sent as JSON or as a classic form post. */
function sg_input(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $raw = file_get_contents('php://input') ?: '';
    $data = [];
    if ($raw !== '' && str_starts_with(ltrim($raw), '{')) {
        $data = json_decode($raw, true) ?: [];
    }
    $cache = array_merge($_GET, $_POST, is_array($data) ? $data : []);
    return $cache;
}

function sg_str(string $key, string $default = ''): string
{
    $v = sg_input()[$key] ?? $default;
    return is_scalar($v) ? trim((string) $v) : $default;
}

function sg_int(string $key, int $default = 0): int
{
    $v = sg_input()[$key] ?? $default;
    return is_numeric($v) ? (int) $v : $default;
}

function sg_bool(string $key, bool $default = false): bool
{
    $in = sg_input();
    if (!array_key_exists($key, $in)) {
        return $default;
    }
    $v = $in[$key];
    if (is_bool($v)) {
        return $v;
    }
    return in_array(strtolower((string) $v), ['1', 'true', 'on', 'yes'], true);
}

/** Load the job named in the request, or fail with a clear message. */
function sg_job(): Job
{
    $id = sg_str('id');
    if ($id === '') {
        sg_fail('No job id was sent.');
    }
    if (!Job::exists($id)) {
        sg_fail('That job no longer exists. It may have been deleted.', 404);
    }
    return Job::load($id);
}
