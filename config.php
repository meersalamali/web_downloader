<?php
declare(strict_types=1);

/**
 * SiteGrabber configuration.
 * Edit the values below to change global limits and defaults.
 */
return [
    'app_name'    => 'SiteGrabber',
    'version'     => '1.0.0',

    // Where jobs, raw downloads and zip files live.
    'storage_dir' => __DIR__ . '/storage',

    // Seconds of work per AJAX "tick". Keep below your PHP max_execution_time.
    // Lower this to 3.0 on free shared hosting, which often caps execution at 10s.
    'tick_seconds' => 6.0,

    // How outgoing requests are made. '' = detect automatically, which is right
    // almost always. Shared hosts frequently disable the curl_multi_* functions,
    // and the detection handles that on its own. Pin it only to debug:
    //   'multi'  parallel cURL (fastest)
    //   'single' one cURL request at a time
    //   'stream' no cURL at all, uses PHP streams (needs allow_url_fopen)
    'force_http_mode' => '',

    // Hard ceilings a user cannot exceed from the UI (safety rails).
    'hard_limits' => [
        'max_pages'    => 5000,
        'max_assets'   => 20000,
        'max_depth'    => 20,
        'max_file_mb'  => 200,
        'max_total_mb' => 4000,
        'concurrency'  => 12,
    ],

    // Default crawl options shown in the UI.
    'defaults' => [
        'max_pages'          => 150,
        'max_assets'         => 1500,
        'max_depth'          => 6,
        'max_file_mb'        => 25,
        'max_total_mb'       => 600,
        'concurrency'        => 5,
        'delay_ms'           => 150,
        'timeout'            => 25,
        'respect_robots'     => true,
        'include_subdomains' => false,
        'external_assets'    => true,
        'use_sitemap'        => true,
        'scan_js'            => false,
        'strip_integrity'    => true,
        'keep_absolute'      => true,  // leave non-downloaded links pointing at the live site
        'verify_ssl'         => false, // XAMPP often ships without a CA bundle
        'user_agent'         => '',    // blank = use the default below
        'cookie'             => '',
        'auth_user'          => '',
        'auth_pass'          => '',
        'types'              => [
            'css'   => true,
            'js'    => true,
            'img'   => true,
            'font'  => true,
            'media' => false,
            'doc'   => true,
        ],
    ],

    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
                  . 'Chrome/124.0 Safari/537.36 SiteGrabber/1.0 (offline archiver)',

    // Local project export (your own sites in htdocs).
    'local_export' => [
        'roots'   => ['C:/xampp/htdocs'],
        'db_host' => '127.0.0.1',
        'db_port' => 3306,
        'db_user' => 'root',
        'db_pass' => '',
        'skip_dirs' => ['node_modules', '.git', '.svn', 'vendor/bin', '.idea', '.vscode', 'storage/jobs'],
    ],
];
