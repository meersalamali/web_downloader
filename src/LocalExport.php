<?php
declare(strict_types=1);

/**
 * Export a project that lives on THIS machine: the real .php source files from
 * disk plus a real MySQL dump. This is the only honest way to get PHP and a
 * database, because neither is ever sent over HTTP by a web server.
 *
 * Phases: init -> db -> package -> done
 */
final class LocalExport
{
    private Job $job;

    public function __construct(Job $job)
    {
        $this->job = $job;
    }

    /* ------------------------------------------------------------------ discovery */

    /** Folders that look like projects, directly under the configured roots. */
    public static function listProjects(): array
    {
        $cfg = (array) sg_config('local_export');
        $out = [];
        foreach ((array) ($cfg['roots'] ?? []) as $root) {
            $root = rtrim(Util::slashes((string) $root), '/');
            if (!is_dir($root)) {
                continue;
            }
            foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
                $dir = Util::slashes($dir);
                $name = basename($dir);
                if ($name === '' || $name[0] === '.') {
                    continue;
                }
                $files = glob($dir . '/*.{php,html,htm}', GLOB_BRACE) ?: [];
                $out[] = [
                    'path'  => $dir,
                    'name'  => $name,
                    'root'  => $root,
                    'entry' => $files !== [] ? basename($files[0]) : '',
                ];
            }
        }
        usort($out, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return $out;
    }

    public static function listDatabases(): array
    {
        $cfg = (array) sg_config('local_export');
        try {
            $pdo = self::connect(null);
        } catch (Throwable $e) {
            return ['error' => $e->getMessage(), 'databases' => []];
        }
        $skip = ['information_schema', 'performance_schema', 'mysql', 'sys'];
        $rows = [];
        foreach ($pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN) as $db) {
            if (in_array(strtolower((string) $db), $skip, true)) {
                continue;
            }
            $n = 0;
            try {
                $n = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '
                    . $pdo->quote((string) $db))->fetchColumn();
            } catch (Throwable $e) {
                // table count is a nicety, not worth failing over
            }
            $rows[] = ['name' => (string) $db, 'tables' => $n];
        }
        return ['error' => null, 'databases' => $rows, 'host' => $cfg['db_host'] ?? '', 'user' => $cfg['db_user'] ?? ''];
    }

    private static function connect(?string $db): PDO
    {
        $cfg = (array) sg_config('local_export');
        $dsn = 'mysql:host=' . ($cfg['db_host'] ?? '127.0.0.1')
             . ';port=' . ($cfg['db_port'] ?? 3306)
             . ($db !== null ? ';dbname=' . $db : '')
             . ';charset=utf8mb4';
        return new PDO($dsn, (string) ($cfg['db_user'] ?? 'root'), (string) ($cfg['db_pass'] ?? ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 10,
        ]);
    }

    /** Reject anything outside the configured roots. */
    public static function validatePath(string $path): string
    {
        $real = realpath($path);
        if ($real === false || !is_dir($real)) {
            throw new RuntimeException('That folder does not exist: ' . $path);
        }
        $real = rtrim(Util::slashes($real), '/');
        foreach ((array) (sg_config('local_export')['roots'] ?? []) as $root) {
            $rootReal = realpath((string) $root);
            if ($rootReal === false) {
                continue;
            }
            $rootReal = rtrim(Util::slashes($rootReal), '/');
            if ($real === $rootReal || str_starts_with($real . '/', $rootReal . '/')) {
                return $real;
            }
        }
        throw new RuntimeException('For safety, only folders inside ' . implode(' or ',
            (array) (sg_config('local_export')['roots'] ?? [])) . ' can be exported.');
    }

    /* ------------------------------------------------------------------ tick */

    public function tick(int $logFrom = 0): array
    {
        @set_time_limit(0);

        if (!$this->job->acquireLock()) {
            $snap = $this->job->snapshot($logFrom);
            $snap['busy'] = true;
            return $snap;
        }

        try {
            if ($this->job->isRunning()) {
                $deadline = microtime(true) + (float) sg_config('tick_seconds', 6.0);
                switch ($this->job->phase()) {
                    case Job::PHASE_INIT:
                        $this->initPhase();
                        break;
                    case 'db':
                        $this->dbSlice($deadline);
                        break;
                    case Job::PHASE_PACKAGE:
                        $this->packageSlice($deadline);
                        break;
                }
                $this->job->save();
            }
        } catch (Throwable $e) {
            $this->job->log('error', 'Stopped: ' . $e->getMessage());
            $this->job->finish('error', $e->getMessage());
            try {
                $this->job->save();
            } catch (Throwable $ignored) {
                // nothing useful left to do
            }
        } finally {
            $this->job->releaseLock();
        }

        return $this->job->snapshot($logFrom);
    }

    private function initPhase(): void
    {
        $job = $this->job;
        $dir = (string) $job->opt('local_path', '');
        $dir = self::validatePath($dir);

        $job->log('info', 'Scanning ' . $dir);
        $files = $this->collect($dir);
        if ($files === []) {
            throw new RuntimeException('No files found in ' . $dir);
        }
        Util::writeJson($job->dir('pack.json'), $files);

        $bytes = 0;
        $php = 0;
        foreach ($files as $rel) {
            $bytes += (int) @filesize($dir . '/' . $rel);
            if (strtolower(pathinfo($rel, PATHINFO_EXTENSION)) === 'php') {
                $php++;
            }
        }
        $job->meta['pack_total'] = count($files);
        $job->meta['pack_at'] = 0;
        $job->meta['php_files'] = $php;
        $job->bump('bytes', $bytes);
        $job->log('ok', 'Found ' . count($files) . ' file(s), ' . $php . ' PHP file(s), ' . Util::bytes($bytes));

        $dbs = array_values(array_filter((array) $job->opt('databases', [])));
        $job->meta['db_queue'] = $dbs;
        $job->meta['db_state'] = ['db' => 0, 'table' => 0, 'offset' => 0, 'tables' => null];

        if ($dbs !== []) {
            $job->log('info', 'Will export database(s): ' . implode(', ', $dbs));
            $job->setPhase('db');
        } else {
            $job->log('muted', 'No database selected - exporting files only');
            $job->setPhase(Job::PHASE_PACKAGE);
        }
    }

    /** @return string[] project-relative paths */
    private function collect(string $dir): array
    {
        $cfg = (array) sg_config('local_export');
        $skipDirs = array_map('strtolower', (array) ($cfg['skip_dirs'] ?? []));
        $extra = array_filter(array_map('trim', explode(',', (string) $this->job->opt('skip_dirs', ''))));
        foreach ($extra as $x) {
            $skipDirs[] = strtolower($x);
        }
        $maxMb = (int) $this->job->opt('max_file_mb', 50);
        $limit = $maxMb * 1024 * 1024;

        $out = [];
        $prefix = strlen($dir) + 1;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $f) {
            /** @var SplFileInfo $f */
            $rel = substr(Util::slashes($f->getPathname()), $prefix);
            if ($rel === false || $rel === '') {
                continue;
            }
            $lower = strtolower($rel);

            $skipped = false;
            foreach ($skipDirs as $s) {
                if ($s !== '' && ($lower === $s || str_starts_with($lower, $s . '/') || str_contains($lower, '/' . $s . '/'))) {
                    $skipped = true;
                    break;
                }
            }
            if ($skipped) {
                continue;
            }
            if ($f->isFile()) {
                if ($limit > 0 && $f->getSize() > $limit) {
                    $this->job->note('Skipped ' . $rel . ' (larger than ' . $maxMb . ' MB)');
                    continue;
                }
                $out[] = $rel;
            }
        }
        sort($out);
        return $out;
    }

    /* ------------------------------------------------------------------ database */

    private function dbSlice(float $deadline): void
    {
        $job = $this->job;
        $queue = (array) $job->meta['db_queue'];
        $st = (array) $job->meta['db_state'];

        Util::ensureDir($job->dir('sql'));

        while (microtime(true) < $deadline) {
            $dbIndex = (int) $st['db'];
            if ($dbIndex >= count($queue)) {
                $job->setPhase(Job::PHASE_PACKAGE);
                $job->meta['pack_at'] = 0;
                $job->log('ok', 'Database export finished');
                break;
            }
            $dbName = (string) $queue[$dbIndex];
            $sqlFile = $job->dir('sql/' . Util::safeSegment($dbName) . '.sql');
            $pdo = self::connect($dbName);

            // First visit to this database: write the header and list the tables.
            if ($st['tables'] === null) {
                // Views must come last: a view selects from tables, so creating it
                // first fails with "table doesn't exist" on restore.
                $base = [];
                $views = [];
                foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
                    $isView = strtoupper((string) ($row[1] ?? '')) === 'VIEW';
                    $entry = ['name' => (string) $row[0], 'view' => $isView];
                    if ($isView) {
                        $views[] = $entry;
                    } else {
                        $base[] = $entry;
                    }
                }
                $tables = array_merge($base, $views);
                $st['tables'] = $tables;
                $st['table'] = 0;
                $st['offset'] = 0;
                @file_put_contents($sqlFile, $this->sqlHeader($dbName, count($tables)));
                $job->log('info', $dbName . ': ' . count($tables) . ' table(s)');
            }

            $tables = (array) $st['tables'];
            $ti = (int) $st['table'];
            if ($ti >= count($tables)) {
                @file_put_contents($sqlFile, $this->sqlFooter(), FILE_APPEND);
                $job->log('ok', $dbName . ' exported to ' . basename($sqlFile));
                $st = ['db' => $dbIndex + 1, 'table' => 0, 'offset' => 0, 'tables' => null];
                $job->meta['db_state'] = $st;
                continue;
            }

            $table = (string) $tables[$ti]['name'];
            $isView = (bool) $tables[$ti]['view'];
            $quoted = '`' . str_replace('`', '``', $table) . '`';

            // Structure, written once per table.
            if ((int) $st['offset'] === 0) {
                $create = (string) ($pdo->query('SHOW CREATE TABLE ' . $quoted)->fetch(PDO::FETCH_NUM)[1] ?? '');
                if ($isView) {
                    // A hard-coded DEFINER fails on any server without that exact
                    // user, so drop it and let the importing account own the view.
                    $create = (string) preg_replace(
                        '/\s*DEFINER\s*=\s*`(?:[^`]|``)*`@`(?:[^`]|``)*`|\s*SQL SECURITY\s+(?:DEFINER|INVOKER)/i',
                        '',
                        $create
                    );
                }
                $sql = "\n--\n-- " . ($isView ? 'View' : 'Table') . ' structure for ' . $table . "\n--\n\n"
                     . 'DROP ' . ($isView ? 'VIEW' : 'TABLE') . ' IF EXISTS ' . $quoted . ";\n"
                     . $create . ";\n";
                @file_put_contents($sqlFile, $sql, FILE_APPEND);
            }

            if ($isView) {
                $st['table'] = $ti + 1;
                $st['offset'] = 0;
                $job->meta['db_state'] = $st;
                continue;
            }

            // Data, in chunks so a huge table cannot blow the time limit.
            $chunk = 400;
            $offset = (int) $st['offset'];
            $rows = $pdo->query('SELECT * FROM ' . $quoted . ' LIMIT ' . $chunk . ' OFFSET ' . $offset)
                        ->fetchAll(PDO::FETCH_ASSOC);

            if ($rows === []) {
                if ($offset > 0) {
                    @file_put_contents($sqlFile, "\n", FILE_APPEND);
                }
                $job->bump('assets_done');
                $st['table'] = $ti + 1;
                $st['offset'] = 0;
                $job->meta['db_state'] = $st;
                continue;
            }

            if ($offset === 0) {
                @file_put_contents($sqlFile, "\n--\n-- Data for " . $table . "\n--\n\n", FILE_APPEND);
            }
            @file_put_contents($sqlFile, $this->insertStatements($pdo, $quoted, $rows), FILE_APPEND);

            $st['offset'] = $offset + count($rows);
            $job->meta['db_state'] = $st;
            $job->log('muted', '   ' . $table . ': ' . $st['offset'] . ' row(s)');
        }

        $job->meta['db_state'] = $st;
    }

    private function insertStatements(PDO $pdo, string $quotedTable, array $rows): string
    {
        $cols = array_keys($rows[0]);
        $colList = implode(', ', array_map(static fn($c) => '`' . str_replace('`', '``', (string) $c) . '`', $cols));

        $values = [];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($cols as $c) {
                $cells[] = $this->sqlValue($pdo, $row[$c]);
            }
            $values[] = '(' . implode(',', $cells) . ')';
        }
        return 'INSERT INTO ' . $quotedTable . ' (' . $colList . ') VALUES' . "\n"
             . implode(",\n", $values) . ";\n";
    }

    private function sqlValue(PDO $pdo, $v): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        $s = (string) $v;
        // Binary data cannot go in a quoted string safely; use a hex literal.
        if ($s !== '' && (!mb_check_encoding($s, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $s))) {
            return '0x' . bin2hex($s);
        }
        return $pdo->quote($s);
    }

    private function sqlHeader(string $db, int $tables): string
    {
        $q = '`' . str_replace('`', '``', $db) . '`';
        return "-- " . sg_config('app_name') . ' ' . sg_config('version') . " - MySQL dump\n"
             . "-- Database : {$db}\n"
             . '-- Tables   : ' . $tables . "\n"
             . '-- Exported : ' . date('Y-m-d H:i:s') . "\n"
             . "--\n"
             . "-- Restore with:  mysql -u root -p {$db} < " . Util::safeSegment($db) . ".sql\n"
             . "-- or import this file through phpMyAdmin.\n"
             . "--\n\n"
             . "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n"
             . "SET time_zone = '+00:00';\n"
             . "SET NAMES utf8mb4;\n"
             . "SET FOREIGN_KEY_CHECKS = 0;\n\n"
             . "CREATE DATABASE IF NOT EXISTS {$q} DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n"
             . "USE {$q};\n";
    }

    private function sqlFooter(): string
    {
        return "\nSET FOREIGN_KEY_CHECKS = 1;\n-- Dump complete\n";
    }

    /* ------------------------------------------------------------------ package */

    private function packageSlice(float $deadline): void
    {
        $job = $this->job;
        $files = Util::readJson($job->dir('pack.json'), []);
        $total = count($files);
        $at = (int) ($job->meta['pack_at'] ?? 0);

        $dir = (string) $job->opt('local_path', '');
        $folder = Util::safeSegment(basename($dir) !== '' ? basename($dir) : 'project');
        $zipPath = $job->dir('out/' . $folder . '-source-' . date('Ymd-His', (int) $job->meta['created']) . '.zip');

        $zip = $at === 0 || !is_file($zipPath)
            ? ZipWriter::create($zipPath, 6)
            : ZipWriter::resume($zipPath, (array) ($job->meta['zip_state'] ?? []), 6);

        try {
            while ($at < $total && microtime(true) < $deadline) {
                $rel = (string) $files[$at];
                $at++;
                $zip->addFile($dir . '/' . $rel, $folder . '/' . $rel);
                if ($at % 200 === 0) {
                    $job->log('muted', '   added ' . $at . ' / ' . $total . ' file(s)');
                }
            }
            $job->meta['counters']['pages_done'] = $at;

            if ($at >= $total) {
                foreach (glob($job->dir('sql') . '/*.sql') ?: [] as $sqlFile) {
                    $zip->addFile($sqlFile, $folder . '/_database/' . basename($sqlFile));
                    $job->log('ok', 'Added ' . basename($sqlFile) . ' (' . Util::bytes((int) filesize($sqlFile)) . ')');
                }
                $zip->addString($this->readme($dir, $folder), $folder . '/_database/HOW-TO-RESTORE.txt');
                $size = $zip->finish();

                $job->meta['zip'] = [
                    'name' => basename($zipPath),
                    'size' => $size,
                    'size_h' => Util::bytes($size),
                    'files' => $zip->count(),
                ];
                $job->meta['zip_state'] = null;
                $job->meta['pack_at'] = $at;
                $job->finish('done');
                $job->log('done', 'ZIP ready: ' . basename($zipPath) . ' (' . Util::bytes($size) . ')');
                return;
            }

            $job->meta['zip_state'] = $zip->state();
            $job->meta['pack_at'] = $at;
            $zip->close();
        } catch (Throwable $e) {
            $zip->close();
            throw $e;
        }
    }

    private function readme(string $dir, string $folder): string
    {
        $dbs = (array) $this->job->opt('databases', []);
        $lines = [];
        $lines[] = 'PROJECT SOURCE EXPORT';
        $lines[] = str_repeat('=', 60);
        $lines[] = '';
        $lines[] = 'Source folder : ' . $dir;
        $lines[] = 'Exported      : ' . date('Y-m-d H:i:s');
        $lines[] = 'PHP files     : ' . (int) ($this->job->meta['php_files'] ?? 0);
        $lines[] = '';
        $lines[] = 'This archive contains the REAL source code read from disk, including every';
        $lines[] = '.php file - not the HTML a browser would see.';
        $lines[] = '';
        if ($dbs !== []) {
            $lines[] = 'DATABASE DUMPS (in this _database folder)';
            $lines[] = '----------------------------------------';
            foreach ($dbs as $db) {
                $lines[] = '  ' . Util::safeSegment((string) $db) . '.sql   ->  database "' . $db . '"';
            }
            $lines[] = '';
            $lines[] = 'To restore on another machine, either:';
            $lines[] = '';
            $lines[] = '  1) Command line:';
            $lines[] = '       mysql -u root -p < ' . Util::safeSegment((string) $dbs[0]) . '.sql';
            $lines[] = '';
            $lines[] = '  2) phpMyAdmin:';
            $lines[] = '       Import tab -> choose the .sql file -> Go';
            $lines[] = '';
            $lines[] = 'Each dump already contains CREATE DATABASE and USE, so it will recreate';
            $lines[] = 'the database if it does not exist.';
        } else {
            $lines[] = 'No database was selected for this export.';
        }
        $lines[] = '';
        $lines[] = 'TO RUN THE SITE';
        $lines[] = '---------------';
        $lines[] = '  1) Copy the "' . $folder . '" folder into your web root (for XAMPP: C:/xampp/htdocs).';
        $lines[] = '  2) Import the .sql file(s) above.';
        $lines[] = '  3) Check the database name, user and password in the project config file';
        $lines[] = '     (often config.php, db.php, .env or includes/connection.php).';
        $lines[] = '  4) Open http://localhost/' . $folder . '/ in a browser.';
        $lines[] = '';
        $lines[] = 'NOTE: config files may contain passwords and API keys. Treat this archive';
        $lines[] = 'as sensitive and do not share it publicly.';
        $lines[] = '';
        return implode("\r\n", $lines);
    }
}
