<?php
declare(strict_types=1);

/** URL parsing, RFC-3986 style resolution, scope tests and URL -> local path mapping. */
final class Url
{
    public const TYPE_BY_EXT = [
        'css'   => ['css'],
        'js'    => ['js', 'mjs', 'cjs'],
        'img'   => ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif', 'ico', 'bmp', 'tif', 'tiff', 'apng', 'cur'],
        'font'  => ['woff', 'woff2', 'ttf', 'otf', 'eot'],
        'media' => ['mp4', 'webm', 'ogv', 'ogg', 'oga', 'mp3', 'wav', 'flac', 'm4a', 'm4v', 'mov', 'avi', 'mkv', 'wmv', '3gp'],
        'doc'   => ['pdf', 'zip', 'rar', '7z', 'gz', 'tar', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
                    'csv', 'txt', 'xml', 'json', 'rss', 'atom', 'webmanifest', 'map', 'vtt', 'srt'],
        'html'  => ['html', 'htm', 'xhtml', 'shtml', 'php', 'php3', 'php4', 'php5', 'phtml',
                    'asp', 'aspx', 'jsp', 'jspx', 'cfm', 'cgi', 'pl', 'py', 'rb', 'do', 'action'],
    ];

    /** Server-side extensions: a page here means there is PHP/ASP/etc source we cannot see. */
    public const DYNAMIC_EXT = ['php', 'php3', 'php4', 'php5', 'phtml', 'asp', 'aspx', 'jsp',
                                'jspx', 'cfm', 'cgi', 'pl', 'py', 'rb', 'do', 'action'];

    public static function isHttp(string $u): bool
    {
        return (bool) preg_match('#^https?://#i', $u);
    }

    /** Resolve a possibly relative reference against a base URL. Returns null for mailto:, javascript:, data:, #frag. */
    public static function resolve(string $base, string $ref): ?string
    {
        $ref = trim(html_entity_decode($ref, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $ref = preg_replace('/[\r\n\t]+/', '', $ref) ?? '';

        if ($ref === '' || $ref[0] === '#') {
            return null;
        }
        // Any explicit scheme: keep only http(s).
        if (preg_match('#^([a-z][a-z0-9+.\-]*):#i', $ref, $m)) {
            return self::isHttp($ref) ? self::normalize($ref) : null;
        }

        $b = parse_url($base);
        if (!$b || empty($b['host'])) {
            return null;
        }
        $scheme = strtolower($b['scheme'] ?? 'http');
        $auth   = $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');

        if (str_starts_with($ref, '//')) {
            return self::normalize($scheme . ':' . $ref);
        }
        if ($ref[0] === '/') {
            return self::normalize($scheme . '://' . $auth . $ref);
        }
        $basePath = $b['path'] ?? '/';
        if ($ref[0] === '?') {
            return self::normalize($scheme . '://' . $auth . ($basePath === '' ? '/' : $basePath) . $ref);
        }
        $cut = strrpos($basePath, '/');
        $dir = $cut === false ? '/' : substr($basePath, 0, $cut + 1);
        if ($dir === '') {
            $dir = '/';
        }
        return self::normalize($scheme . '://' . $auth . $dir . $ref);
    }

    /** Canonical form: lowercase scheme/host, no default port, no fragment, collapsed dot segments. */
    public static function normalize(string $u): string
    {
        $u = trim($u);
        $hash = strpos($u, '#');
        if ($hash !== false) {
            $u = substr($u, 0, $hash);
        }
        $p = parse_url($u);
        if (!$p || empty($p['host'])) {
            return '';
        }
        $scheme = strtolower($p['scheme'] ?? 'http');
        $host   = strtolower($p['host']);
        $port   = $p['port'] ?? null;
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }
        $path = self::canonPath($p['path'] ?? '/');
        $query = $p['query'] ?? '';

        return $scheme . '://' . $host . ($port ? ':' . $port : '') . $path
             . ($query !== '' ? '?' . $query : '');
    }

    private static function canonPath(string $path): string
    {
        if ($path === '') {
            return '/';
        }
        $path = preg_replace('#/{2,}#', '/', $path) ?? $path;
        $endSlash = str_ends_with($path, '/');
        $out = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $seg;
        }
        $res = '/' . implode('/', $out);
        if ($endSlash && $res !== '/') {
            $res .= '/';
        }
        // Spaces and a few stray characters break HTTP requests.
        return str_replace([' ', '"', '<', '>', '{', '}', '|', chr(92), '^', '`'],
                           ['%20', '%22', '%3C', '%3E', '%7B', '%7D', '%7C', '%5C', '%5E', '%60'], $res);
    }

    public static function host(string $u): string
    {
        return strtolower((string) (parse_url($u, PHP_URL_HOST) ?: ''));
    }

    public static function path(string $u): string
    {
        $p = parse_url($u, PHP_URL_PATH);
        return ($p === null || $p === false || $p === '') ? '/' : (string) $p;
    }

    /** Naive registrable domain: good enough to decide "is this a subdomain of the site". */
    public static function baseDomain(string $host): string
    {
        $host = strtolower(trim($host, '.'));
        $parts = explode('.', $host);
        $n = count($parts);
        if ($n <= 2) {
            return $host;
        }
        $twoLevelTlds = ['co', 'com', 'net', 'org', 'gov', 'edu', 'ac', 'or', 'ne', 'go', 'mil', 'sch'];
        if (in_array($parts[$n - 2], $twoLevelTlds, true) && strlen($parts[$n - 1]) <= 3) {
            return implode('.', array_slice($parts, -3));
        }
        return implode('.', array_slice($parts, -2));
    }

    public static function sameSite(string $url, string $rootHost, bool $includeSubdomains): bool
    {
        $h = self::host($url);
        if ($h === '') {
            return false;
        }
        if ($h === $rootHost) {
            return true;
        }
        // www.example.com and example.com are always treated as one site.
        if (preg_replace('/^www\./', '', $h) === preg_replace('/^www\./', '', $rootHost)) {
            return true;
        }
        return $includeSubdomains && self::baseDomain($h) === self::baseDomain($rootHost);
    }

    /** Guess a resource type from the URL extension. Returns one of the TYPE_BY_EXT keys, or '' if unknown. */
    public static function typeFromUrl(string $u): string
    {
        $ext = strtolower(pathinfo(parse_url($u, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
        if ($ext === '') {
            return '';
        }
        foreach (self::TYPE_BY_EXT as $type => $exts) {
            if (in_array($ext, $exts, true)) {
                return $type;
            }
        }
        return '';
    }

    public static function extension(string $u): string
    {
        return strtolower(pathinfo(parse_url($u, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
    }

    public static function isDynamic(string $u): bool
    {
        return in_array(self::extension($u), self::DYNAMIC_EXT, true);
    }

    /** Map a resource type to the type bucket used by the UI checkboxes. */
    public static function typeFromMime(string $mime): string
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));
        if ($mime === '') {
            return '';
        }
        if (str_contains($mime, 'html')) {
            return 'html';
        }
        if ($mime === 'text/css') {
            return 'css';
        }
        if (preg_match('#(javascript|ecmascript)#', $mime)) {
            return 'js';
        }
        if (str_starts_with($mime, 'image/')) {
            return 'img';
        }
        if (str_starts_with($mime, 'font/') || preg_match('#(font-woff|woff|opentype|truetype|font-sfnt)#', $mime)) {
            return 'font';
        }
        if (str_starts_with($mime, 'video/') || str_starts_with($mime, 'audio/')) {
            return 'media';
        }
        return 'doc';
    }

    private const MIME_EXT = [
        'text/html' => 'html', 'application/xhtml+xml' => 'html', 'text/css' => 'css',
        'text/javascript' => 'js', 'application/javascript' => 'js', 'application/x-javascript' => 'js',
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/svg+xml' => 'svg',
        'image/webp' => 'webp', 'image/avif' => 'avif', 'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico',
        'font/woff' => 'woff', 'font/woff2' => 'woff2', 'font/ttf' => 'ttf', 'font/otf' => 'otf',
        'application/font-woff' => 'woff', 'application/vnd.ms-fontobject' => 'eot',
        'application/json' => 'json', 'application/xml' => 'xml', 'text/xml' => 'xml',
        'text/plain' => 'txt', 'application/pdf' => 'pdf', 'application/zip' => 'zip',
        'video/mp4' => 'mp4', 'video/webm' => 'webm', 'audio/mpeg' => 'mp3', 'audio/ogg' => 'ogg',
        'audio/wav' => 'wav', 'application/manifest+json' => 'webmanifest',
    ];

    public static function extForMime(string $mime): string
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));
        return self::MIME_EXT[$mime] ?? '';
    }

    /**
     * Turn an absolute URL into a site-relative file path.
     * Content type decides the final extension, so this runs after the fetch.
     */
    public static function toLocalPath(string $url, string $type, string $rootHost): string
    {
        $p = parse_url($url);
        $host  = strtolower($p['host'] ?? $rootHost);
        $path  = $p['path'] ?? '/';
        $query = $p['query'] ?? '';

        $segments = array_values(array_filter(explode('/', $path), static fn($s) => $s !== ''));
        $endsWithSlash = $path === '' || str_ends_with($path, '/');

        $last = $endsWithSlash ? '' : (string) array_pop($segments);
        $segments = array_map([Util::class, 'safeSegment'], $segments);

        $ext = strtolower(pathinfo($last, PATHINFO_EXTENSION));

        if ($type === 'html') {
            if ($last === '') {
                $last = 'index.html';
            } elseif ($ext === 'html' || $ext === 'htm') {
                $last = Util::safeSegment($last);
            } elseif ($ext === '' ) {
                // Extension-less page: /about -> about/index.html keeps relative links inside it working.
                $segments[] = Util::safeSegment($last);
                $last = 'index.html';
            } else {
                // /product.php -> product.php.html keeps the original name visible.
                $last = Util::safeSegment($last) . '.html';
            }
        } else {
            if ($last === '') {
                $last = 'index' . ($ext !== '' ? '.' . $ext : '');
                if ($last === 'index') {
                    $last = 'index.' . ($type !== '' ? $type : 'bin');
                }
            } else {
                $last = Util::safeSegment($last);
                if (pathinfo($last, PATHINFO_EXTENSION) === '') {
                    $guess = match ($type) {
                        'css' => 'css', 'js' => 'js', 'img' => 'png', 'font' => 'woff2',
                        'media' => 'mp4', default => 'bin',
                    };
                    $last .= '.' . $guess;
                }
            }
        }

        // A query string makes a different page at the same path: fold it into the name.
        if ($query !== '') {
            $tag = '__' . substr(sha1($query), 0, 7);
            $e = pathinfo($last, PATHINFO_EXTENSION);
            $n = pathinfo($last, PATHINFO_FILENAME);
            $last = $n . $tag . ($e !== '' ? '.' . $e : '');
        }

        $prefix = [];
        if ($host !== $rootHost && preg_replace('/^www\./', '', $host) !== preg_replace('/^www\./', '', $rootHost)) {
            $prefix = ['_external', Util::safeSegment($host)];
        }

        $all = array_merge($prefix, $segments, [$last]);
        $all = array_values(array_filter($all, static fn($s) => $s !== '' && $s !== '.'));

        // Windows has a 260 character path limit by default; shorten deep trees.
        $joined = implode('/', $all);
        if (strlen($joined) > 180) {
            $file = array_pop($all);
            $joined = implode('/', array_slice($all, 0, 2)) . '/_deep/' . substr(sha1($joined), 0, 10) . '/' . $file;
        }
        return ltrim($joined, '/');
    }
}
