<?php
declare(strict_types=1);

/**
 * Finds every URL in HTML and CSS, and rewrites it to point at the local copy.
 *
 * This works on the raw markup with targeted regular expressions instead of a DOM
 * parser on purpose: the saved page then stays byte-for-byte identical to the
 * original except for the URLs we deliberately changed. A DOM round-trip would
 * silently "fix" HTML5 markup, move tags and break inline templates.
 *
 * Script, style, comment and textarea bodies are lifted out before the tag scan
 * so that a "<" inside JavaScript can never be mistaken for a tag.
 */
final class Rewriter
{
    /** Attributes whose value is a single URL. */
    private const URL_ATTRS = [
        'href', 'src', 'poster', 'action', 'formaction', 'data-src', 'data-href', 'data-url',
        'data-original', 'data-lazy-src', 'data-background', 'data-bg', 'data-bg-image',
        'data-image', 'data-poster', 'data-thumb', 'background', 'cite', 'longdesc',
        'manifest', 'profile', 'xlink:href', 'data', 'lowsrc',
    ];

    /** Attributes holding a comma separated candidate list. */
    private const SRCSET_ATTRS = ['srcset', 'data-srcset', 'imagesrcset'];

    private const SKIP_PREFIX = ['data:', 'javascript:', 'mailto:', 'tel:', 'sms:', 'about:',
                                 'blob:', 'ftp:', 'file:', 'chrome:', 'ws:', 'wss:', '#'];

    /* ------------------------------------------------------------------ public API */

    /** Absolute base for the document, honouring <base href>. */
    public static function baseHref(string $html, string $docUrl): string
    {
        if (preg_match('#<base\b[^>]*\bhref\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))#i', $html, $m)) {
            $val = $m[2] !== '' ? $m[2] : ($m[3] !== '' ? $m[3] : ($m[4] ?? ''));
            $abs = Url::resolve($docUrl, trim($val));
            if ($abs !== null && $abs !== '') {
                return $abs;
            }
        }
        return $docUrl;
    }

    /**
     * Collect every reference in a document.
     * @return array<int,array{url:string,kind:string,type:string}>
     */
    public static function extractHtml(string $html, string $baseUrl, bool $scanJs = false): array
    {
        $found = [];
        self::walkHtml($html, $baseUrl, static function (string $url, string $kind, string $type) use (&$found) {
            $found[$url] = ['url' => $url, 'kind' => $kind, 'type' => $type];
            return null;                      // collect only, change nothing
        }, ['scan_js' => $scanJs, 'strip_integrity' => false]);
        return array_values($found);
    }

    /** @return array<int,array{url:string,kind:string,type:string}> */
    public static function extractCss(string $css, string $baseUrl): array
    {
        $found = [];
        self::walkCss($css, $baseUrl, static function (string $url, string $kind, string $type) use (&$found) {
            $found[$url] = ['url' => $url, 'kind' => $kind, 'type' => $type];
            return null;
        });
        return array_values($found);
    }

    /** Opt-in: pull obvious asset paths out of JavaScript string literals. */
    public static function extractJs(string $js, string $baseUrl): array
    {
        $found = [];
        $re = '#[\'"`]([^\'"`\s<>(){}]{2,300}\.(?:css|js|mjs|png|jpe?g|gif|svg|webp|avif|ico|'
            . 'woff2?|ttf|otf|eot|json|webmanifest|mp4|webm|mp3|wav|ogg)(?:\?[^\'"`\s]{0,200})?)[\'"`]#i';
        if (preg_match_all($re, $js, $m)) {
            foreach ($m[1] as $raw) {
                if (self::skippable($raw) || str_contains($raw, '${') || str_contains($raw, '" +')) {
                    continue;
                }
                $abs = Url::resolve($baseUrl, $raw);
                if ($abs === null || $abs === '') {
                    continue;
                }
                $type = Url::typeFromUrl($abs) ?: 'doc';
                if ($type === 'html') {
                    continue;                 // a page name inside JS is usually a route, not a file
                }
                $found[$abs] = ['url' => $abs, 'kind' => 'asset', 'type' => $type];
            }
        }
        return array_values($found);
    }

    /**
     * Walk (and optionally rewrite) a document.
     * $onUrl(absoluteUrl, kind, type) returns a replacement string, or null to leave it alone.
     */
    public static function walkHtml(string $html, string $baseUrl, callable $onUrl, array $opt = []): string
    {
        $opt += ['scan_js' => false, 'strip_integrity' => true];

        $store = [];
        $keep = static function (string $s) use (&$store): string {
            $key = "\x01SGP" . count($store) . "\x02";
            $store[$key] = $s;
            return $key;
        };

        // 1. Lift out anything that must not be scanned as markup.
        $html = (string) preg_replace_callback('#<!--.*?-->#s',
            static fn(array $m) => $keep($m[0]), $html);

        $html = (string) preg_replace_callback('#(<script\b[^>]*>)(.*?)(</script\s*>)#is',
            static function (array $m) use ($keep, $onUrl, $baseUrl, $opt): string {
                $body = $m[2];
                if ($opt['scan_js'] && trim($body) !== '') {
                    foreach (self::extractJs($body, $baseUrl) as $hit) {
                        $onUrl($hit['url'], $hit['kind'], $hit['type']);
                    }
                }
                return $m[1] . $keep($body) . $m[3];
            }, $html);

        $html = (string) preg_replace_callback('#(<style\b[^>]*>)(.*?)(</style\s*>)#is',
            static fn(array $m) => $m[1] . $keep(self::walkCss($m[2], $baseUrl, $onUrl)) . $m[3], $html);

        $html = (string) preg_replace_callback('#(<textarea\b[^>]*>)(.*?)(</textarea\s*>)#is',
            static fn(array $m) => $m[1] . $keep($m[2]) . $m[3], $html);

        // 2. Rewrite attributes tag by tag.
        $html = (string) preg_replace_callback(
            '#<([a-zA-Z][a-zA-Z0-9:_-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>#s',
            static function (array $m) use ($onUrl, $baseUrl, $opt): string {
                return '<' . $m[1] . self::rewriteAttrs(strtolower($m[1]), $m[2], $baseUrl, $onUrl, $opt) . '>';
            },
            $html
        );

        // 3. Put the protected blocks back.
        if ($store) {
            $html = strtr($html, $store);
        }
        return $html;
    }

    /** Walk (and optionally rewrite) a stylesheet or an inline style value. */
    public static function walkCss(string $css, string $baseUrl, callable $onUrl): string
    {
        $handle = static function (string $raw) use ($baseUrl, $onUrl): ?string {
            $raw = trim($raw);
            if ($raw === '' || self::skippable($raw)) {
                return null;
            }
            $abs = Url::resolve($baseUrl, $raw);
            if ($abs === null || $abs === '') {
                return null;
            }
            $type = Url::typeFromUrl($abs);
            if ($type === '' || $type === 'html') {
                $type = str_contains($abs, '.css') ? 'css' : 'img';
            }
            return $onUrl($abs, 'asset', $type);
        };

        // url(...) covers both plain rules and "@import url(...)".
        $css = (string) preg_replace_callback(
            '#url\(\s*(["\']?)([^"\')]+)\1\s*\)#i',
            static function (array $m) use ($handle): string {
                $rep = $handle($m[2]);
                if ($rep === null) {
                    return $m[0];
                }
                $q = $m[1] !== '' ? $m[1] : '"';
                return 'url(' . $q . str_replace(['"', "'", '(', ')'], ['%22', '%27', '%28', '%29'], $rep) . $q . ')';
            },
            $css
        );

        // "@import 'x';" without the url() wrapper.
        $css = (string) preg_replace_callback(
            '#@import\s+(["\'])([^"\']+)\1#i',
            static function (array $m) use ($handle): string {
                $rep = $handle($m[2]);
                return $rep === null ? $m[0] : '@import ' . $m[1] . $rep . $m[1];
            },
            $css
        );

        return $css;
    }

    /* ------------------------------------------------------------------ internals */

    private static function rewriteAttrs(string $tag, string $attrs, string $baseUrl, callable $onUrl, array $opt): string
    {
        // Pre-read the attributes that decide what a URL means.
        $ctx = [
            'rel'        => strtolower(self::attr($attrs, 'rel')),
            'as'         => strtolower(self::attr($attrs, 'as')),
            'type'       => strtolower(self::attr($attrs, 'type')),
            'http-equiv' => strtolower(self::attr($attrs, 'http-equiv')),
            'property'   => strtolower(self::attr($attrs, 'property') ?: self::attr($attrs, 'name')),
        ];

        return (string) preg_replace_callback(
            '#([a-zA-Z_:][a-zA-Z0-9_:.\-]*)(\s*=\s*)("([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))#s',
            static function (array $m) use ($tag, $ctx, $baseUrl, $onUrl, $opt): string {
                $name = strtolower($m[1]);
                $quoted = $m[3];
                if (isset($m[4]) && $quoted !== '' && $quoted[0] === '"') {
                    $q = '"';
                    $val = $m[4];
                } elseif (isset($m[5]) && $quoted !== '' && $quoted[0] === "'") {
                    $q = "'";
                    $val = $m[5];
                } else {
                    $q = '';
                    $val = $m[6] ?? '';
                }

                $emit = static function (string $newValue) use ($m, $q): string {
                    $enc = str_replace('&', '&amp;', $newValue);
                    if ($q === '') {
                        return $m[1] . $m[2] . (preg_match('/[\s>"\']/', $enc) ? '"' . $enc . '"' : $enc);
                    }
                    $enc = str_replace($q, $q === '"' ? '&quot;' : '&#39;', $enc);
                    return $m[1] . $m[2] . $q . $enc . $q;
                };

                // Subresource integrity would block a file loaded from disk.
                if ($opt['strip_integrity'] && in_array($name, ['integrity', 'crossorigin'], true)) {
                    return '';
                }

                if (in_array($name, self::SRCSET_ATTRS, true)) {
                    $new = self::walkSrcset($val, $baseUrl, $onUrl);
                    return $new === null ? $m[0] : $emit($new);
                }

                if ($name === 'style') {
                    // Entities must go before the CSS scan, or url(&quot;x&quot;) yields %22 in the path.
                    $decoded = html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $new = self::walkCss($decoded, $baseUrl, $onUrl);
                    return $new === $decoded ? $m[0] : $emit($new);
                }

                if ($tag === 'meta' && $name === 'content') {
                    $new = self::metaContent($val, $ctx, $baseUrl, $onUrl);
                    return $new === null ? $m[0] : $emit($new);
                }

                if (!in_array($name, self::URL_ATTRS, true)) {
                    return $m[0];
                }
                // <base href> is neutralised separately; leaving it in would break every relative link.
                if ($tag === 'base' && $name === 'href') {
                    return $m[1] . $m[2] . ($q !== '' ? $q : '"') . './' . ($q !== '' ? $q : '"');
                }

                $raw = trim(html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($raw === '' || self::skippable($raw)) {
                    return $m[0];
                }

                $class = self::classify($tag, $name, $raw, $ctx);
                if ($class === null) {
                    return $m[0];
                }
                $abs = Url::resolve($baseUrl, $raw);
                if ($abs === null || $abs === '') {
                    return $m[0];
                }

                $fragment = '';
                $hash = strpos($raw, '#');
                if ($hash !== false && $hash > 0) {
                    $fragment = substr($raw, $hash);
                }

                $rep = $onUrl($abs, $class[0], $class[1]);
                return $rep === null ? $m[0] : $emit($rep . $fragment);
            },
            $attrs
        );
    }

    /** Rewrite a srcset candidate list. Returns null if nothing changed. */
    private static function walkSrcset(string $value, string $baseUrl, callable $onUrl): ?string
    {
        if ($value === '' || stripos($value, 'data:') !== false) {
            return null;   // data: URIs may contain commas, which breaks splitting
        }
        $changed = false;
        $out = [];
        foreach (preg_split('/\s*,\s*/', trim($value)) ?: [] as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $item, 2);
            $url = $parts[0];
            $desc = isset($parts[1]) ? ' ' . $parts[1] : '';

            if (self::skippable($url)) {
                $out[] = $item;
                continue;
            }
            $abs = Url::resolve($baseUrl, html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($abs === null || $abs === '') {
                $out[] = $item;
                continue;
            }
            $type = Url::typeFromUrl($abs);
            $rep = $onUrl($abs, 'asset', $type === '' || $type === 'html' ? 'img' : $type);
            if ($rep === null) {
                $out[] = $item;
            } else {
                $out[] = $rep . $desc;
                $changed = true;
            }
        }
        return $changed ? implode(', ', $out) : null;
    }

    /** <meta http-equiv=refresh> and og:image / twitter:image. */
    private static function metaContent(string $val, array $ctx, string $baseUrl, callable $onUrl): ?string
    {
        if ($ctx['http-equiv'] === 'refresh') {
            if (!preg_match('/^(\s*[\d.]+\s*;\s*url\s*=\s*)(.+)$/i', $val, $m)) {
                return null;
            }
            $target = trim($m[2], " \t'\"");
            if (self::skippable($target)) {
                return null;
            }
            $abs = Url::resolve($baseUrl, $target);
            if ($abs === null || $abs === '') {
                return null;
            }
            $rep = $onUrl($abs, 'page', 'html');
            return $rep === null ? null : $m[1] . $rep;
        }

        $imageProps = ['og:image', 'og:image:url', 'og:image:secure_url', 'twitter:image',
                       'twitter:image:src', 'og:audio', 'og:video', 'msapplication-tileimage'];
        if (in_array($ctx['property'], $imageProps, true) && Url::isHttp($val)) {
            $abs = Url::normalize($val);
            if ($abs === '') {
                return null;
            }
            $type = Url::typeFromUrl($abs) ?: 'img';
            return $onUrl($abs, 'asset', $type);
        }
        return null;
    }

    /**
     * Decide whether a reference is a page to crawl or an asset to download,
     * and which asset bucket it belongs to.
     * @return array{0:string,1:string}|null null = ignore this reference
     */
    private static function classify(string $tag, string $attr, string $raw, array $ctx): ?array
    {
        $ext = strtolower(pathinfo(parse_url($raw, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
        $byExt = '';
        foreach (Url::TYPE_BY_EXT as $t => $exts) {
            if ($ext !== '' && in_array($ext, $exts, true)) {
                $byExt = $t;
                break;
            }
        }

        switch ($tag) {
            case 'a':
            case 'area':
                if ($byExt !== '' && $byExt !== 'html') {
                    return ['asset', $byExt];
                }
                return ['page', 'html'];

            case 'link':
                $rel = $ctx['rel'];
                if ($rel === '') {
                    return $byExt !== '' ? ['asset', $byExt] : null;
                }
                if (str_contains($rel, 'stylesheet')) {
                    return ['asset', 'css'];
                }
                if (str_contains($rel, 'icon')) {
                    return ['asset', 'img'];
                }
                if (str_contains($rel, 'manifest')) {
                    return ['asset', 'doc'];
                }
                if (str_contains($rel, 'modulepreload')) {
                    return ['asset', 'js'];
                }
                if (str_contains($rel, 'preload')) {
                    return match ($ctx['as']) {
                        'style'  => ['asset', 'css'],
                        'script' => ['asset', 'js'],
                        'font'   => ['asset', 'font'],
                        'image'  => ['asset', 'img'],
                        'video', 'audio' => ['asset', 'media'],
                        default  => $byExt !== '' ? ['asset', $byExt] : null,
                    };
                }
                if (preg_match('/\b(canonical|alternate|next|prev|index|start|up|contents)\b/', $rel)) {
                    return ['page', 'html'];
                }
                if (preg_match('/\b(preconnect|dns-prefetch|prerender|pingback|profile|license|author|help|search)\b/', $rel)) {
                    return null;
                }
                return $byExt !== '' ? ['asset', $byExt] : null;

            case 'script':
                return $attr === 'src' ? ['asset', 'js'] : null;

            case 'img':
            case 'image':
            case 'use':
                return ['asset', $byExt !== '' && $byExt !== 'html' ? $byExt : 'img'];

            case 'input':
                return ['asset', 'img'];

            case 'source':
                return ['asset', $byExt === 'media' ? 'media' : ($byExt !== '' && $byExt !== 'html' ? $byExt : 'img')];

            case 'video':
            case 'audio':
                if ($attr === 'poster') {
                    return ['asset', 'img'];
                }
                return ['asset', 'media'];

            case 'track':
                return ['asset', 'doc'];

            case 'embed':
            case 'object':
                return ['asset', $byExt !== '' && $byExt !== 'html' ? $byExt : 'doc'];

            case 'iframe':
            case 'frame':
                return $byExt !== '' && $byExt !== 'html' ? ['asset', $byExt] : ['page', 'html'];

            case 'body':
            case 'table':
            case 'td':
            case 'th':
                return $attr === 'background' ? ['asset', 'img'] : null;

            case 'form':
                return ['page', 'html'];

            case 'base':
                return null;

            default:
                if ($byExt !== '' && $byExt !== 'html') {
                    return ['asset', $byExt];
                }
                return $attr === 'href' ? ['page', 'html'] : null;
        }
    }

    private static function attr(string $attrs, string $name): string
    {
        $re = '#\b' . preg_quote($name, '#') . '\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))#i';
        if (!preg_match($re, $attrs, $m)) {
            return '';
        }
        if (($m[2] ?? '') !== '') {
            return $m[2];
        }
        if (($m[3] ?? '') !== '') {
            return $m[3];
        }
        return $m[4] ?? '';
    }

    private static function skippable(string $url): bool
    {
        $u = strtolower(ltrim($url));
        if ($u === '' || $u === '#') {
            return true;
        }
        foreach (self::SKIP_PREFIX as $p) {
            if (str_starts_with($u, $p)) {
                return true;
            }
        }
        // Template placeholders from a server-side or JS templating engine.
        return (bool) preg_match('/\{\{|\}\}|\{%|%\}|<\?|\$\{/', $url);
    }
}
