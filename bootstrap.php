<?php
declare(strict_types=1);

if (defined('SG_BOOTSTRAPPED')) {
    return;
}
define('SG_BOOTSTRAPPED', true);
define('SG_ROOT', __DIR__);

mb_internal_encoding('UTF-8');
ignore_user_abort(true);

$GLOBALS['SG_CONFIG'] = require __DIR__ . '/config.php';

spl_autoload_register(static function (string $class): void {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $class)) {
        return;
    }
    $file = SG_ROOT . '/src/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});

function sg_config(?string $key = null, $default = null)
{
    $cfg = $GLOBALS['SG_CONFIG'];
    if ($key === null) {
        return $cfg;
    }
    return $cfg[$key] ?? $default;
}

function sg_storage(string $sub = ''): string
{
    $dir = rtrim(Util::slashes((string) sg_config('storage_dir')), '/');
    return $sub === '' ? $dir : $dir . '/' . ltrim(Util::slashes($sub), '/');
}

Util::ensureDir(sg_storage('jobs'));
Util::denyListing(sg_storage());
Util::denyListing(sg_storage('jobs'));
