<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

// Sent from PHP rather than .htaccess: shared hosts often reject directives in
// .htaccess outright, and a refused one makes Apache 500 on every request.
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$def  = (array) sg_config('defaults');
$hard = (array) sg_config('hard_limits');
$app  = (string) sg_config('app_name');
$ver  = (string) sg_config('version');

/** Checkbox helper: keeps the markup below readable. */
function sg_check(string $name, string $label, bool $on, string $hint = ''): string
{
    $id = 'o_' . $name;
    return '<label class="check" for="' . $id . '">'
        . '<input type="checkbox" id="' . $id . '" name="' . $name . '"' . ($on ? ' checked' : '') . '>'
        . '<span class="box"></span>'
        . '<span class="ct"><span class="cl">' . htmlspecialchars($label) . '</span>'
        . ($hint !== '' ? '<span class="ch">' . htmlspecialchars($hint) . '</span>' : '')
        . '</span></label>';
}

function sg_num(string $name, string $label, int $value, int $min, int $max, string $hint = ''): string
{
    $id = 'o_' . $name;
    return '<div class="field"><label for="' . $id . '">' . htmlspecialchars($label) . '</label>'
        . '<input type="number" id="' . $id . '" name="' . $name . '" value="' . $value . '" '
        . 'min="' . $min . '" max="' . $max . '">'
        . ($hint !== '' ? '<span class="hint">' . htmlspecialchars($hint) . '</span>' : '')
        . '</div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($app) ?> &middot; Download any website</title>
<meta name="description" content="Download every page, stylesheet, script and image of a website and get it back as a ZIP that works offline.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css?v=<?= htmlspecialchars($ver) ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>&#128190;</text></svg>">
</head>
<body>

<div class="glow glow-a"></div>
<div class="glow glow-b"></div>

<header class="topbar">
  <div class="brand">
    <div class="logo">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
        <polyline points="7 10 12 15 17 10"></polyline>
        <line x1="12" y1="15" x2="12" y2="3"></line>
      </svg>
    </div>
    <div class="brandtext">
      <strong><?= htmlspecialchars($app) ?></strong>
      <span>website archiver</span>
    </div>
  </div>
  <nav class="tabs" id="tabs">
    <button class="tab on" data-tab="remote">Download a website</button>
    <button class="tab" data-tab="local">Local project</button>
    <button class="tab" data-tab="history">History</button>
    <button class="tab" data-tab="run" id="tabRun" hidden>Progress</button>
  </nav>
</header>

<main class="wrap">

  <!-- ============================================ REMOTE ============================================ -->
  <section class="panel on" id="tab-remote">

    <div class="hero">
      <h1>Download a whole website</h1>
      <p class="lede">
        Give it an address. It crawls every page it can reach, pulls in the HTML, CSS, JavaScript,
        images and fonts, rewrites all the links, and hands you a ZIP that opens offline.
      </p>
    </div>

    <form class="urlform" id="startForm" autocomplete="off">
      <div class="urlrow">
        <span class="urlicon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line>
            <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10A15.3 15.3 0 0 1 12 2z"></path>
          </svg>
        </span>
        <input type="text" id="url" name="url" placeholder="example.com  or  https://example.com/blog" spellcheck="false" required>
        <button type="submit" class="btn primary big" id="startBtn">
          <span class="label">Download site</span>
          <svg class="arr" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline>
          </svg>
        </button>
      </div>

      <div class="presets" id="presets">
        <span class="plabel">Preset</span>
        <button type="button" class="chip" data-preset="quick">Quick &middot; 30 pages</button>
        <button type="button" class="chip on" data-preset="standard">Standard &middot; 150</button>
        <button type="button" class="chip" data-preset="deep">Deep &middot; 600</button>
        <button type="button" class="chip" data-preset="max">Everything</button>
      </div>

      <details class="adv" id="adv">
        <summary>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <circle cx="12" cy="12" r="3"></circle>
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6 1.65 1.65 0 0 0 10 3.09V3a2 2 0 0 1 4 0v.09A1.65 1.65 0 0 0 15 4.6a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9v.09a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
          </svg>
          Advanced options
        </summary>

        <div class="advbody">
          <div class="group">
            <h3>Limits</h3>
            <div class="grid4">
              <?= sg_num('max_pages',   'Max pages',      (int) $def['max_pages'],    1, (int) $hard['max_pages'],    'HTML pages to visit') ?>
              <?= sg_num('max_depth',   'Max depth',      (int) $def['max_depth'],    0, (int) $hard['max_depth'],    'clicks from the start page') ?>
              <?= sg_num('max_assets',  'Max files',      (int) $def['max_assets'],   0, (int) $hard['max_assets'],   'css, js, images, fonts') ?>
              <?= sg_num('max_file_mb', 'Max file size',  (int) $def['max_file_mb'],  1, (int) $hard['max_file_mb'],  'megabytes, per file') ?>
              <?= sg_num('max_total_mb','Max total size', (int) $def['max_total_mb'], 1, (int) $hard['max_total_mb'], 'megabytes, whole site') ?>
              <?= sg_num('concurrency', 'Parallel downloads', (int) $def['concurrency'], 1, (int) $hard['concurrency'], 'at the same time') ?>
              <?= sg_num('delay_ms',    'Delay',          (int) $def['delay_ms'],     0, 10000, 'ms between batches') ?>
              <?= sg_num('timeout',     'Timeout',        (int) $def['timeout'],      5, 120,  'seconds per request') ?>
            </div>
          </div>

          <div class="group">
            <h3>What to include</h3>
            <div class="grid3">
              <?= sg_check('type_css',   'Stylesheets', (bool) $def['types']['css'],   '.css') ?>
              <?= sg_check('type_js',    'Scripts',     (bool) $def['types']['js'],    '.js') ?>
              <?= sg_check('type_img',   'Images',      (bool) $def['types']['img'],   'png, jpg, svg, webp') ?>
              <?= sg_check('type_font',  'Fonts',       (bool) $def['types']['font'],  'woff, woff2, ttf') ?>
              <?= sg_check('type_media', 'Video &amp; audio', (bool) $def['types']['media'], 'can be very large') ?>
              <?= sg_check('type_doc',   'Documents',   (bool) $def['types']['doc'],   'pdf, xml, json') ?>
            </div>
          </div>

          <div class="group">
            <h3>Crawl behaviour</h3>
            <div class="grid2">
              <?= sg_check('respect_robots',     'Respect robots.txt', (bool) $def['respect_robots'], 'recommended - crawl only what the site permits') ?>
              <?= sg_check('use_sitemap',        'Read sitemap.xml', (bool) $def['use_sitemap'], 'finds pages nothing links to') ?>
              <?= sg_check('include_subdomains', 'Include subdomains', (bool) $def['include_subdomains'], 'blog.site.com, shop.site.com') ?>
              <?= sg_check('external_assets',    'Files from other domains', (bool) $def['external_assets'], 'CDN stylesheets, fonts, images') ?>
              <?= sg_check('scan_js',            'Scan JavaScript for files', (bool) $def['scan_js'], 'finds more, guesses sometimes') ?>
              <?= sg_check('keep_absolute',      'Keep missing links online', (bool) $def['keep_absolute'], 'links we did not download still work') ?>
              <?= sg_check('strip_integrity',    'Strip integrity checks', (bool) $def['strip_integrity'], 'needed for local CDN copies to load') ?>
              <?= sg_check('verify_ssl',         'Verify HTTPS certificates', (bool) $def['verify_ssl'], 'off by default: XAMPP ships no CA list') ?>
            </div>
          </div>

          <div class="group">
            <h3>Requests</h3>
            <div class="grid2">
              <div class="field span2">
                <label for="o_user_agent">User agent</label>
                <input type="text" id="o_user_agent" name="user_agent" placeholder="leave empty to look like Chrome">
              </div>
              <div class="field span2">
                <label for="o_cookie">Cookie header</label>
                <input type="text" id="o_cookie" name="cookie" placeholder="session=abc123; lang=en   (for logged-in pages)">
                <span class="hint">Paste your own session cookie to archive pages that need a login.</span>
              </div>
              <div class="field">
                <label for="o_auth_user">HTTP auth user</label>
                <input type="text" id="o_auth_user" name="auth_user" autocomplete="off">
              </div>
              <div class="field">
                <label for="o_auth_pass">HTTP auth password</label>
                <input type="password" id="o_auth_pass" name="auth_pass" autocomplete="new-password">
              </div>
              <div class="field span2">
                <label for="o_skip_ext">Skip these extensions</label>
                <input type="text" id="o_skip_ext" name="skip_ext" placeholder="zip, exe, iso, dmg">
              </div>
            </div>
          </div>
        </div>
      </details>
    </form>

    <div class="notice">
      <div class="nicon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line>
          <line x1="12" y1="8" x2="12.01" y2="8"></line>
        </svg>
      </div>
      <div class="ntext">
        <strong>About PHP files and databases</strong>
        <p>
          A web server <em>runs</em> PHP and sends back only the finished HTML, so <code>.php</code>
          source code and MySQL data never travel over the internet. No downloader can retrieve them
          from a live site &mdash; this one included. Pages built by PHP are saved here as the HTML they
          produced.
        </p>
        <p>
          If the site is <strong>yours and sitting on this computer</strong>, open the
          <button type="button" class="linkbtn" data-goto="local">Local project</button> tab instead:
          that reads the real <code>.php</code> files from disk and exports a real <code>.sql</code> dump.
        </p>
      </div>
    </div>
  </section>

  <!-- ============================================ LOCAL ============================================ -->
  <section class="panel" id="tab-local">
    <div class="hero">
      <h1>Export a local project</h1>
      <p class="lede">
        For sites on this machine. This copies the actual source files &mdash; PHP included &mdash;
        straight from disk, and dumps any MySQL database you pick into a <code>.sql</code> file.
      </p>
    </div>

    <form class="localform" id="localForm" autocomplete="off">
      <div class="group">
        <h3>Project folder</h3>
        <div id="projList" class="projlist">
          <div class="loading">Looking for projects&hellip;</div>
        </div>
        <div class="field">
          <label for="localPath">Folder path</label>
          <input type="text" id="localPath" name="path" placeholder="C:/xampp/htdocs/my-site">
          <span class="hint" id="rootsHint"></span>
        </div>
      </div>

      <div class="group">
        <h3>Databases</h3>
        <div id="dbList" class="dblist">
          <div class="loading">Connecting to MySQL&hellip;</div>
        </div>
      </div>

      <div class="group">
        <h3>Options</h3>
        <div class="grid2">
          <div class="field">
            <label for="l_skip">Skip folders</label>
            <input type="text" id="l_skip" name="skip_dirs" placeholder="node_modules, .git, cache">
            <span class="hint">node_modules and .git are always skipped.</span>
          </div>
          <div class="field">
            <label for="l_maxmb">Max file size</label>
            <input type="number" id="l_maxmb" name="max_file_mb" value="50" min="1" max="500">
            <span class="hint">megabytes, per file</span>
          </div>
        </div>
      </div>

      <button type="submit" class="btn primary big" id="localBtn">
        <span class="label">Export source &amp; database</span>
        <svg class="arr" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
          <line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline>
        </svg>
      </button>
    </form>
  </section>

  <!-- ============================================ HISTORY ============================================ -->
  <section class="panel" id="tab-history">
    <div class="hero">
      <h1>History</h1>
      <p class="lede">Everything you have downloaded, newest first. Archives stay on disk until you delete them.</p>
    </div>
    <div id="historyList" class="history">
      <div class="loading">Loading&hellip;</div>
    </div>
  </section>

  <!-- ============================================ PROGRESS ============================================ -->
  <section class="panel" id="tab-run">
    <div class="runhead">
      <div class="ring" id="ring">
        <svg viewBox="0 0 120 120">
          <circle class="track" cx="60" cy="60" r="52"></circle>
          <circle class="bar" id="ringBar" cx="60" cy="60" r="52"></circle>
        </svg>
        <div class="rtext"><span id="pct">0</span><i>%</i></div>
      </div>
      <div class="runinfo">
        <div class="phase" id="phaseLabel">Starting&hellip;</div>
        <h2 id="runTitle">&nbsp;</h2>
        <div class="runurl" id="runUrl">&nbsp;</div>
        <div class="runbtns">
          <button type="button" class="btn ghost" id="cancelBtn">Stop</button>
          <button type="button" class="btn ghost" id="backBtn" hidden>New download</button>
        </div>
      </div>
    </div>

    <div class="stats" id="stats">
      <div class="stat"><div class="sv" id="s_pages">0</div><div class="sl">Pages</div></div>
      <div class="stat"><div class="sv" id="s_files">0</div><div class="sl">Files</div></div>
      <div class="stat"><div class="sv" id="s_size">0 B</div><div class="sl">Downloaded</div></div>
      <div class="stat"><div class="sv" id="s_queue">0</div><div class="sl">In queue</div></div>
      <div class="stat"><div class="sv" id="s_fail">0</div><div class="sl">Failed</div></div>
      <div class="stat"><div class="sv" id="s_time">0s</div><div class="sl">Elapsed</div></div>
    </div>

    <div class="result" id="result" hidden></div>
    <div class="notes" id="notes" hidden></div>

    <div class="console">
      <div class="chead">
        <span class="dots"><i></i><i></i><i></i></span>
        <span class="ctitle">Activity</span>
        <label class="autoscroll"><input type="checkbox" id="autoscroll" checked> follow</label>
      </div>
      <div class="clog" id="log"></div>
    </div>
  </section>

</main>

<footer class="foot">
  <span><?= htmlspecialchars($app) ?> <?= htmlspecialchars($ver) ?></span>
  <span class="sep">&middot;</span>
  <span>Only download sites you own or have permission to copy, and respect the content's licence.</span>
</footer>

<div class="toast" id="toast"></div>

<script src="assets/js/app.js?v=<?= htmlspecialchars($ver) ?>"></script>
</body>
</html>
