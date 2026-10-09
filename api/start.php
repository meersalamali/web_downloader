<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

$url = sg_str('url');
if ($url === '') {
    sg_fail('Please enter a website address.');
}
if (!preg_match('#^https?://#i', $url)) {
    $url = 'https://' . ltrim($url, '/');
}
$url = Url::normalize($url);
if ($url === '' || Url::host($url) === '') {
    sg_fail('That address does not look valid. Example: https://example.com');
}
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    sg_fail('That address could not be parsed. Example: https://example.com/page');
}

$hard = (array) sg_config('hard_limits');
$def  = (array) sg_config('defaults');

$types = [];
foreach (['css', 'js', 'img', 'font', 'media', 'doc'] as $t) {
    $types[$t] = sg_bool('type_' . $t, (bool) ($def['types'][$t] ?? true));
}

$options = [
    'max_pages'          => (int) Util::clamp(sg_int('max_pages', $def['max_pages']), 1, $hard['max_pages']),
    'max_assets'         => (int) Util::clamp(sg_int('max_assets', $def['max_assets']), 0, $hard['max_assets']),
    'max_depth'          => (int) Util::clamp(sg_int('max_depth', $def['max_depth']), 0, $hard['max_depth']),
    'max_file_mb'        => (int) Util::clamp(sg_int('max_file_mb', $def['max_file_mb']), 1, $hard['max_file_mb']),
    'max_total_mb'       => (int) Util::clamp(sg_int('max_total_mb', $def['max_total_mb']), 1, $hard['max_total_mb']),
    'concurrency'        => (int) Util::clamp(sg_int('concurrency', $def['concurrency']), 1, $hard['concurrency']),
    'delay_ms'           => (int) Util::clamp(sg_int('delay_ms', $def['delay_ms']), 0, 10000),
    'timeout'            => (int) Util::clamp(sg_int('timeout', $def['timeout']), 5, 120),
    'respect_robots'     => sg_bool('respect_robots', $def['respect_robots']),
    'include_subdomains' => sg_bool('include_subdomains', $def['include_subdomains']),
    'external_assets'    => sg_bool('external_assets', $def['external_assets']),
    'use_sitemap'        => sg_bool('use_sitemap', $def['use_sitemap']),
    'scan_js'            => sg_bool('scan_js', $def['scan_js']),
    'strip_integrity'    => sg_bool('strip_integrity', $def['strip_integrity']),
    'keep_absolute'      => sg_bool('keep_absolute', $def['keep_absolute']),
    'verify_ssl'         => sg_bool('verify_ssl', $def['verify_ssl']),
    'user_agent'         => sg_str('user_agent', ''),
    'cookie'             => sg_str('cookie', ''),
    'auth_user'          => sg_str('auth_user', ''),
    'auth_pass'          => sg_str('auth_pass', ''),
    'skip_ext'           => sg_str('skip_ext', ''),
    'types'              => $types,
];

$job = Job::create($options, $url, 'crawl');

sg_json(['ok' => true, 'id' => $job->id, 'snapshot' => $job->snapshot(0)]);
