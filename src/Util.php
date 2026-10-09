<?php
declare(strict_types=1);

/** Small filesystem / formatting helpers used across the app. */
final class Util
{
    /** Windows device names that cannot be used as a file or folder name. */
    private const RESERVED = [
        'con', 'prn', 'aux', 'nul',
        'com1', 'com2', 'com3', 'com4', 'com5', 'com6', 'com7', 'com8', 'com9',
        'lpt1', 'lpt2', 'lpt3', 'lpt4', 'lpt5', 'lpt6', 'lpt7', 'lpt8', 'lpt9',
    ];

    public static function ensureDir(string $dir): void
    {
        if ($dir !== '' && !is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create directory: ' . $dir);
        }
    }

    public static function bytes(int $n): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $v = (float) $n;
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            $i++;
        }
        return ($i === 0 ? (string) (int) $v : number_format($v, $v < 10 ? 2 : 1)) . ' ' . $units[$i];
    }

    public static function uid(): string
    {
        return date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 6);
    }

    /** Normalise any path to forward slashes. */
    public static function slashes(string $path): string
    {
        return str_replace(DIRECTORY_SEPARATOR === '/' ? '/' : chr(92), '/', $path);
    }

    /** Make one path segment safe for Windows and for zip archives. */
    public static function safeSegment(string $s): string
    {
        $s = rawurldecode($s);
        $s = str_replace(chr(92), '/', $s);
        $s = preg_replace('/[\x00-\x1F\x7F]/u', '', $s) ?? '';
        $s = preg_replace('#[<>:"|?*/]+#u', '-', $s) ?? '';
        $s = trim($s);
        $s = preg_replace('/\s+/u', '-', $s) ?? '';
        $s = trim($s, '.-');

        if ($s === '' || $s === '.' || $s === '..') {
            return '_';
        }
        $base = strtolower(pathinfo($s, PATHINFO_FILENAME));
        if (in_array($base, self::RESERVED, true)) {
            $s = '_' . $s;
        }
        // Keep room for long nested paths on Windows.
        if (strlen($s) > 90) {
            $ext = pathinfo($s, PATHINFO_EXTENSION);
            $s = substr(pathinfo($s, PATHINFO_FILENAME), 0, 70) . '-' . substr(sha1($s), 0, 8)
               . ($ext !== '' ? '.' . $ext : '');
        }
        return $s;
    }

    /**
     * Relative link from one site-relative file to another.
     * relativePath('about/index.html', 'assets/app.css') === '../assets/app.css'
     */
    public static function relativePath(string $fromFile, string $toFile): string
    {
        $fromDir = self::slashes(dirname($fromFile));
        $from = ($fromDir === '.' || $fromDir === '/') ? [] : explode('/', trim($fromDir, '/'));
        $from = array_values(array_filter($from, static fn($s) => $s !== ''));
        $to   = array_values(array_filter(explode('/', $toFile), static fn($s) => $s !== ''));

        while ($from && count($to) > 1 && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }
        $rel = str_repeat('../', count($from)) . implode('/', $to);
        return $rel === '' ? './' : $rel;
    }

    public static function readJson(string $file, $default = [])
    {
        if (!is_file($file)) {
            return $default;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return $default;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : $default;
    }

    /** Write JSON through a temp file so an interrupted tick cannot leave half a file. */
    public static function writeJson(string $file, $data): void
    {
        self::ensureDir(dirname($file));
        $tmp = $file . '.' . getmypid() . '.tmp';
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new RuntimeException('JSON encode failed: ' . json_last_error_msg());
        }
        if (@file_put_contents($tmp, $json) === false) {
            throw new RuntimeException('Cannot write ' . $file);
        }
        // rename() will not overwrite on Windows, so drop the old file first.
        if (!@rename($tmp, $file)) {
            @unlink($file);
            if (!@rename($tmp, $file)) {
                @unlink($tmp);
                throw new RuntimeException('Cannot replace ' . $file);
            }
        }
    }

    public static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            @unlink($dir);
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    /** @return array{0:int,1:int} [fileCount, totalBytes] */
    public static function dirStats(string $dir): array
    {
        if (!is_dir($dir)) {
            return [0, 0];
        }
        $n = 0;
        $b = 0;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            /** @var SplFileInfo $f */
            if ($f->isFile()) {
                $n++;
                $b += $f->getSize();
            }
        }
        return [$n, $b];
    }

    public static function clamp($v, $min, $max)
    {
        return max($min, min($max, $v));
    }
}
