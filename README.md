# SiteGrabber

Download a whole website — every page, stylesheet, script, image and font — and get
it back as a ZIP that opens offline. Built for XAMPP on Windows; plain PHP, no
Composer, no build step, no extra extensions to install.

---

## Read this first: PHP source and databases

This is the one thing most people expect a site downloader to do, and it is the one
thing no downloader can do. It is worth being precise about why.

When you request `product.php`, the web server **runs** that file, queries MySQL, and
sends back only the finished HTML. The `.php` source and the database never travel
over the connection. Your browser never receives them, so neither can this tool,
HTTrack, `wget`, or anything else that speaks HTTP.

So:

| What you want | From a live website | How you actually get it |
|---|---|---|
| HTML of every page | ✅ Yes | the **Download a website** tab |
| CSS, JS, images, fonts | ✅ Yes | same |
| `.php` source code | ❌ Never | FTP/SFTP, cPanel, SSH, Git — or the **Local project** tab |
| MySQL database | ❌ Never | phpMyAdmin export, `mysqldump` — or the **Local project** tab |

Pages produced by PHP are saved as the HTML they generated, with `.html` added to the
name (`product.php` → `product.php.html`). Every archive includes
`_sitegrabber/README.txt` repeating this explanation, so whoever you hand the ZIP to
is not confused either.

**If the site is yours and on this machine**, the *Local project* tab reads the real
`.php` files from disk and writes a real `.sql` dump. That is the honest path to
"PHP + database", and it works properly.

---

## Install

Copy the folder into your web root and open it. That is all.

```
C:/xampp/htdocs/project_web_downloder/
```

Then visit <http://localhost/project_web_downloder/>.

**Requirements** — all standard in XAMPP, already verified on PHP 8.2:

| Needed | Why |
|---|---|
| PHP 8.1+ | the code uses `match`, `str_contains`, typed properties |
| `curl` | downloading, in parallel |
| `zlib` | ZIP compression |
| `pdo_mysql` | the database export only |

The `zip` extension is **not** required. It is disabled by default in XAMPP, so the
archiver is written from scratch against `zlib` ([`src/ZipWriter.php`](src/ZipWriter.php)).
You do not have to touch `php.ini`.

### Check a host before trusting it

Open **`server-check.php`** in a browser on whatever server you deployed to. It reports
PHP version, extensions, limits, whether `storage/` is writable, and — the one that
catches most people out — whether the host permits **outgoing** web requests at all.

Delete the file once you are satisfied; it exposes server settings to anyone who opens it.

---

## Running on shared hosting

It works on shared hosting, including free plans, but such hosts restrict things. The
downloader adapts automatically rather than crashing.

**Download method.** Many hosts disable the `curl_multi_*` family through
`disable_functions`. Three tiers are detected at runtime:

| Mode | When | Effect |
|---|---|---|
| `multi` | parallel cURL available | full speed |
| `single` | `curl_multi_*` disabled | one file at a time — slower, works fine |
| `stream` | cURL entirely unavailable | PHP streams; no HTTP auth support |

You do not configure this. `server-check.php` shows which one your host gets, and the
activity log says so when running in a reduced mode. `force_http_mode` in
`config.php` can pin it if detection ever guesses wrong.

**Execution time.** Free plans often cap PHP at around 10 seconds. If
`server-check.php` warns about this, lower `tick_seconds` in `config.php` to `3.0`.
The crawl simply takes more ticks; nothing is lost.

**Outgoing connections.** Some free hosts block them completely. Nothing can be done
about that from the code — the tool needs to reach the site it is copying. If the
check page fails here, use a different host or run it locally.

**Terms of service.** Shared hosts frequently forbid crawlers and heavy outbound
traffic. Keep the delay and page limits modest, or run it locally where only your own
bandwidth is involved.

---

## Using it

### Download a website

Type an address and press **Download site**. Pick a preset or open
**Advanced options** to set your own limits.

The run view shows a live progress ring, counters, and a streaming log of every URL
as it is fetched. When it finishes you get:

- **Download ZIP** — the archive
- **Open the copy** — browse the rewritten site immediately, no extracting
- **View report** — a filterable table of every URL, its status code, size and saved path

### Options worth knowing

| Option | Default | What it does |
|---|---|---|
| Max pages | 150 | HTML pages to visit. The usual reason a crawl stops early. |
| Max depth | 6 | How many clicks from the start page. |
| Max files | 1500 | Cap on css/js/images/fonts. |
| Respect robots.txt | on | Obeys `Disallow` and `Crawl-delay`. Leave it on. |
| Read sitemap.xml | on | Finds pages nothing links to. |
| Include subdomains | off | Treats `blog.site.com` as part of the site. |
| Files from other domains | on | Pulls CDN stylesheets and fonts into `_external/`. |
| Video & audio | **off** | Media is enormous. Turn on deliberately. |
| Scan JavaScript for files | off | Finds assets referenced only in JS. Guesses sometimes. |
| Keep missing links online | on | Links we did not download become absolute URLs, so they still work. |
| Cookie header | — | Paste your own session cookie to archive pages behind a login. |

### Local project (PHP + MySQL)

Pick a folder under `htdocs` and tick any databases. You get a ZIP with the real
source and a `_database/` folder holding one `.sql` per database, plus
`HOW-TO-RESTORE.txt`.

The dumps are restore-ready: `CREATE DATABASE IF NOT EXISTS` and `USE` are included,
base tables are written before views, and view `DEFINER` clauses are stripped so the
dump imports on any server.

```bash
mysql -u root -p < my_database.sql
```

For safety, only folders inside the roots listed in `config.php` can be exported.

### Command line

Better for big sites — no browser tab to keep open, and it can be scheduled.

```bash
php worker.php --url=https://example.com
php worker.php --url=example.com --pages=800 --depth=12 --media
php worker.php --job=20261009-120501-ab12cd     # resume
php worker.php --list
php worker.php --help
```

---

## How it works

A crawl is split into short **ticks**. The browser calls `api/tick.php`, which does a
few seconds of work and returns; a separate lightweight poll streams the log so the
UI stays live between ticks. Nothing ever runs long enough to hit
`max_execution_time`, and progress survives a closed tab — reopen with
`worker.php --job=<id>`.

```
init  ──▶  crawl  ──▶  rewrite  ──▶  package  ──▶  done
 │          │           │             │
 robots     parallel    links to      ZIP, built
 sitemap    fetch       relative      across ticks
```

Phases are kept separate for a reason: link rewriting cannot start until the crawl is
finished, because a page may link to something not downloaded yet. So the raw bytes
are stored untouched in `raw/`, and the rewrite pass builds the complete URL → file
map first.

### Link rewriting

[`src/Rewriter.php`](src/Rewriter.php) works on the raw markup with targeted regular
expressions rather than a DOM parser, so the saved page stays byte-identical to the
original except for URLs that were deliberately changed. A DOM round-trip would
reformat HTML5 markup and break inline templates.

Script, style, comment and `textarea` bodies are lifted out before the tag scan, so a
`<` inside JavaScript can never be mistaken for a tag. It handles `<base href>`,
`srcset`, inline `style` attributes, `@import`, `url()`, `<meta http-equiv=refresh>`,
`og:image`, unquoted attributes, and skips `data:`, `mailto:` and `{{ template }}`
placeholders.

### File naming

| URL | Saved as |
|---|---|
| `/` | `index.html` |
| `/about` | `about/index.html` |
| `/product.php` | `product.php.html` |
| `/product.php?id=7` | `product.php__c53d0f0.html` |
| `https://cdn.other.com/lib.js` | `_external/cdn.other.com/lib.js` |

Query strings become a short hash, so `?id=7` and `?id=8` are different files.
Windows-illegal characters and reserved names (`con`, `nul`, …) are handled, and deep
paths are shortened to stay under the 260-character limit.

Two URLs in the same directory returning byte-identical content share one file, so
`/` and `/index.html` do not produce both `index.html` and `index-2.html`.

---

## Layout

```
config.php          limits, defaults, MySQL login for the export
bootstrap.php       autoloader and paths
index.php           the interface
worker.php          command line runner
api/                start, tick, status, cancel, download, jobs, delete, local_*
src/
  Crawler.php       init + crawl phases, scope and limit rules
  Packager.php      rewrite + ZIP phases, report and README generation
  Rewriter.php      finds and rewrites every URL in HTML and CSS
  Url.php           RFC-3986 resolution, scope tests, URL → file path
  Http.php          parallel cURL with a hard per-file size cap
  Robots.php        robots.txt: longest-match rules, crawl-delay, sitemaps
  ZipWriter.php     resumable ZIP writer, needs only zlib
  Job.php           job state on disk
  LocalExport.php   local source + MySQL dump
  Util.php          paths, relative links, atomic JSON, formatting
storage/jobs/<id>/  job.json, queue.json, res.json, log.jsonl, raw/, site/, out/
```

`storage/.htaccess` disables script execution and directory listings there, and blocks
the job bookkeeping files — that folder holds bytes downloaded from other sites, so it
is treated as untrusted. Only the rewritten `site/` tree is meant to be browsed.

---

## Tuning

Everything lives in [`config.php`](config.php).

```php
'tick_seconds' => 6.0,            // work per request; keep under max_execution_time
'hard_limits'  => [...],          // ceilings the UI cannot exceed
'defaults'     => [...],          // what the form starts with
'local_export' => [
    'roots'   => ['C:/xampp/htdocs'],   // folders allowed for local export
    'db_user' => 'root',
    'db_pass' => '',                    // set this if your MySQL has a password
],
```

`verify_ssl` is **off** by default because XAMPP ships without a CA bundle, so HTTPS
verification fails on sites that are perfectly fine. Turn it on if you have
`curl.cainfo` configured.

---

## Troubleshooting

**"Call to undefined function curl_multi_exec()"** — your host disabled the parallel
cURL functions. Handled automatically now; make sure you are on the current version.
Run `server-check.php` to confirm which download mode the host gets.

**"This server cannot make outgoing web requests"** — cURL is disabled *and*
`allow_url_fopen` is off, so there is no way to fetch anything. Ask the host to enable
cURL, or run the tool locally.

**It works locally but not on GitHub Pages** — GitHub Pages only serves static files;
it never executes PHP. This is a PHP application, so it cannot run there at all. Worse,
Pages will serve your `.php` files as readable plain text. Use a host that runs PHP.

**"Could not reach MySQL"** — start MySQL in the XAMPP control panel. If your `root`
account has a password, set `db_pass` in `config.php`.

**Crawl stops early** — you hit a limit. The run view lists which one under *Worth
knowing*; raise **Max pages** or **Max depth**.

**A page looks unstyled offline** — the site probably builds its CSS URL in
JavaScript. Try **Scan JavaScript for files**.

**Only the front page downloaded** — the site may render its navigation with
JavaScript. A crawler reads HTML as delivered; it does not run a browser engine, so
links that exist only after JS executes cannot be discovered.

**Login-only pages are missing** — paste your session cookie into **Cookie header**
(copy it from your browser's developer tools).

**Nothing happens / 500 error** — check `C:/xampp/apache/logs/error.log`, and that
`storage/` is writable.

---

## Please be fair about what you download

Technical ability is not permission. Archive sites you own, sites that allow it, or
content you have a right to copy. Keep **Respect robots.txt** on, leave a delay
between requests, and do not republish someone else's work as your own. The default
settings are deliberately polite; hammering a server you do not own with 12 parallel
connections is both rude and likely to get you blocked.
