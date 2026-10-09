<?php
declare(strict_types=1);

/**
 * Server capability check.
 *
 * Open this in a browser on whatever host you deployed to. It reports whether
 * the server can actually run the downloader, and tries a real outgoing request,
 * which is the thing most free hosting plans block.
 *
 * Safe to delete once you are happy the host works.
 */

require __DIR__ . '/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

/** @return array{0:string,1:string,2:string} [state, label, detail] */
function sg_row(bool $ok, string $okText, string $badText, bool $warnOnly = false): array
{
    if ($ok) {
        return ['ok', 'Yes', $okText];
    }
    return [$warnOnly ? 'warn' : 'bad', 'No', $badText];
}

$checks = [];

/* ---------------------------------------------------------------- php */

$phpOk = PHP_VERSION_ID >= 80100;
$checks[] = ['PHP version', $phpOk ? 'ok' : 'bad', PHP_VERSION,
    $phpOk ? 'Fine.' : 'This tool needs PHP 8.1 or newer. Ask your host to switch version.'];

/* ---------------------------------------------------------------- http capability */

$mode = Http::mode();
$modeDesc = [
    'multi'  => 'Parallel cURL available - full speed.',
    'single' => 'The curl_multi_* functions are disabled here, so files download one at a time. Works, just slower.',
    'stream' => 'cURL is unavailable; PHP streams will be used. Slower, and HTTP authentication will not work.',
    'none'   => 'This server cannot make outgoing requests at all. The downloader cannot work here.',
];
$checks[] = ['Download method', $mode === 'multi' ? 'ok' : ($mode === 'none' ? 'bad' : 'warn'),
    $mode, $modeDesc[$mode] ?? ''];

$curlFns = ['curl_init', 'curl_exec', 'curl_setopt_array', 'curl_multi_init', 'curl_multi_exec',
            'curl_multi_add_handle', 'curl_multi_select', 'curl_multi_info_read',
            'curl_multi_remove_handle', 'curl_multi_close'];
$missing = array_values(array_filter($curlFns, static fn($f) => !function_exists($f)));
$checks[] = ['cURL functions', $missing === [] ? 'ok' : 'warn',
    $missing === [] ? 'all present' : count($missing) . ' disabled',
    $missing === [] ? 'Nothing blocked.' : 'Disabled: ' . implode(', ', $missing)];

/* ---------------------------------------------------------------- extensions */

foreach ([
    ['zlib',      true,  'Required to build the ZIP archive.'],
    ['mbstring',  true,  'Required for text handling.'],
    ['dom',       false, 'Optional. Not used for rewriting, but handy to have.'],
    ['pdo_mysql', false, 'Only needed for the local database export.'],
    ['zip',       false, 'Not needed - the tool ships its own ZIP writer.'],
] as [$ext, $required, $why]) {
    $has = extension_loaded($ext);
    $checks[] = ['Extension: ' . $ext, $has ? 'ok' : ($required ? 'bad' : 'warn'),
        $has ? 'loaded' : 'missing', $why];
}

/* ---------------------------------------------------------------- limits */

$maxExec = (int) ini_get('max_execution_time');
$tick = (float) sg_config('tick_seconds', 6.0);
$tickOk = $maxExec === 0 || $maxExec > $tick + 3;
$checks[] = ['max_execution_time', $tickOk ? 'ok' : 'warn',
    $maxExec === 0 ? 'unlimited' : $maxExec . 's',
    $tickOk
        ? 'Leaves room for the ' . $tick . 's work ticks.'
        : 'Too tight for the current tick_seconds of ' . $tick . '. Lower it to about '
          . max(2, (int) floor($maxExec / 3)) . ' in config.php.'];

$checks[] = ['memory_limit', 'ok', (string) ini_get('memory_limit'),
    'Each file is held in memory while it downloads, so keep Max file size below this.'];

$fopen = filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN);
$checks[] = ['allow_url_fopen', $fopen ? 'ok' : 'warn', $fopen ? 'on' : 'off',
    $fopen ? 'Gives a fallback if cURL is blocked.' : 'Only matters if cURL is unavailable.'];

$disabled = trim((string) ini_get('disable_functions'));
if ($disabled !== '') {
    $checks[] = ['disable_functions', 'warn', 'set by host', $disabled];
}

/* ---------------------------------------------------------------- storage */

$storage = sg_storage('jobs');
$writable = is_dir($storage) && is_writable($storage);
if (!$writable) {
    @mkdir($storage, 0777, true);
    $writable = is_dir($storage) && is_writable($storage);
}
$checks[] = ['Storage writable', $writable ? 'ok' : 'bad',
    $writable ? 'yes' : 'no', $writable ? $storage : 'Cannot write to ' . $storage . ' - set it to 755 or 777.'];

/* ---------------------------------------------------------------- .htaccess */

$htaccess = __DIR__ . '/storage/.htaccess';
$checks[] = ['storage/.htaccess', is_file($htaccess) ? 'ok' : 'warn',
    is_file($htaccess) ? 'present' : 'absent',
    is_file($htaccess)
        ? 'Stops downloaded files being executed. If your host ever answers "Something '
          . 'Went Wrong" or HTTP 500 on every page, this file is the first thing to delete - '
          . 'some hosts refuse .htaccess directives and then fail every request.'
        : 'Optional. Without it, job folder names (which are hard to guess) are the only '
          . 'thing keeping stored files private.'];

/* ---------------------------------------------------------------- live outbound test */

$liveState = 'bad';
$liveValue = 'not tested';
$liveDetail = '';
$testUrl = 'https://example.com/';

if ($mode === 'none') {
    $liveDetail = 'Skipped: no way to make requests.';
} else {
    try {
        $http = new Http(['timeout' => 15, 'max_bytes' => 1048576, 'verify_ssl' => false]);
        $r = $http->get($testUrl);
        if ($r['status'] >= 200 && $r['status'] < 400 && $r['size'] > 0) {
            $liveState = 'ok';
            $liveValue = 'HTTP ' . $r['status'] . ' in ' . $r['ms'] . 'ms';
            $liveDetail = 'Fetched ' . Util::bytes($r['size']) . ' from ' . $testUrl
                . ' - outgoing requests work.';
        } else {
            $liveValue = $r['status'] !== 0 ? 'HTTP ' . $r['status'] : 'failed';
            $liveDetail = 'Could not fetch ' . $testUrl . '. ' . ($r['error'] ?: '')
                . ' Many free hosting plans block outgoing connections, which stops this tool working.';
        }
    } catch (Throwable $ex) {
        $liveValue = 'error';
        $liveDetail = $ex->getMessage();
    }
}
$checks[] = ['Outgoing connection', $liveState, $liveValue, $liveDetail];

$fatal = count(array_filter($checks, static fn($c) => $c[1] === 'bad'));
$warn  = count(array_filter($checks, static fn($c) => $c[1] === 'warn'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Server check &middot; <?= $e(sg_config('app_name')) ?></title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#070b12;color:#e9eff8;padding:40px 20px;
     font:15px/1.6 Inter,system-ui,-apple-system,'Segoe UI',Roboto,sans-serif}
.wrap{max-width:860px;margin:0 auto}
h1{font-size:28px;margin:0 0 6px;letter-spacing:-.02em}
.sub{color:#7f93ac;margin-bottom:28px}
.verdict{padding:18px 22px;border-radius:14px;margin-bottom:26px;border:1px solid}
.v-ok{background:rgba(16,185,129,.1);border-color:rgba(52,211,153,.35);color:#6ee7b7}
.v-warn{background:rgba(251,191,36,.09);border-color:rgba(251,191,36,.35);color:#fcd34d}
.v-bad{background:rgba(248,113,113,.1);border-color:rgba(248,113,113,.35);color:#fca5a5}
.verdict strong{display:block;font-size:17px;margin-bottom:4px;color:#fff}
table{width:100%;border-collapse:collapse;background:#0e1420;border:1px solid #1e2836;border-radius:14px;overflow:hidden}
th,td{text-align:left;padding:13px 16px;border-bottom:1px solid #1b2433;vertical-align:top}
tr:last-child td{border-bottom:0}
th{background:#131b29;font-size:11.5px;text-transform:uppercase;letter-spacing:.09em;color:#7f93ac}
.name{font-weight:550;white-space:nowrap}
.val{font-family:ui-monospace,Consolas,monospace;font-size:13px;white-space:nowrap}
.detail{color:#b4c4d8;font-size:13.5px;word-break:break-word}
.pill{display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:650;text-transform:uppercase}
.p-ok{background:rgba(52,211,153,.16);color:#6ee7b7}
.p-warn{background:rgba(251,191,36,.16);color:#fcd34d}
.p-bad{background:rgba(248,113,113,.16);color:#fca5a5}
a{color:#5eb3ff}
.foot{margin-top:24px;color:#7f93ac;font-size:13px}
code{background:#18202f;border:1px solid #1e2836;border-radius:5px;padding:1px 6px;font-size:.9em}
</style>
</head>
<body><div class="wrap">
<h1>Server check</h1>
<div class="sub"><?= $e(sg_config('app_name')) ?> <?= $e(sg_config('version')) ?> &middot; <?= $e(PHP_OS_FAMILY) ?> &middot; PHP <?= $e(PHP_VERSION) ?></div>

<?php if ($fatal > 0): ?>
  <div class="verdict v-bad"><strong>This host cannot run the downloader</strong>
  <?= $fatal ?> blocking problem<?= $fatal === 1 ? '' : 's' ?> below. Fix those, or run it on
  XAMPP on your own computer instead.</div>
<?php elseif ($warn > 0): ?>
  <div class="verdict v-warn"><strong>It will work, with limitations</strong>
  <?= $warn ?> thing<?= $warn === 1 ? '' : 's' ?> to be aware of below. Nothing blocking.</div>
<?php else: ?>
  <div class="verdict v-ok"><strong>All good</strong>
  This server can run everything, at full speed.</div>
<?php endif; ?>

<table>
<thead><tr><th>Check</th><th>Result</th><th>Value</th><th>Notes</th></tr></thead>
<tbody>
<?php foreach ($checks as [$name, $state, $value, $detail]): ?>
  <tr>
    <td class="name"><?= $e($name) ?></td>
    <td><span class="pill p-<?= $e($state) ?>"><?= $e($state === 'ok' ? 'pass' : ($state === 'warn' ? 'note' : 'fail')) ?></span></td>
    <td class="val"><?= $e($value) ?></td>
    <td class="detail"><?= $e($detail) ?></td>
  </tr>
<?php endforeach; ?>
</tbody>
</table>

<p class="foot">
  Back to <a href="./">the downloader</a>. Delete <code>server-check.php</code> when you no
  longer need it &mdash; it exposes server settings to anyone who opens the page.
</p>
</div></body>
</html>
