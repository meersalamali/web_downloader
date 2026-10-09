<?php
declare(strict_types=1);

/**
 * robots.txt parser: longest-match Allow/Disallow rules, Crawl-delay and Sitemap lines.
 * Keeping this on by default means we behave like a well-mannered crawler.
 */
final class Robots
{
    /** @var array<int,array{allow:bool,re:string,len:int}> */
    private array $rules = [];
    private float $delay = 0.0;
    /** @var string[] */
    private array $sitemaps = [];
    private bool $loaded = false;

    public static function none(): self
    {
        return new self();
    }

    public static function parse(string $text, string $ourAgent = 'sitegrabber'): self
    {
        $r = new self();
        $r->loaded = true;
        $ourAgent = strtolower($ourAgent);

        $groups = [];          // agent => list of [allow, path]
        $current = [];         // agents the current block applies to
        $delays  = [];         // agent => seconds
        $lastWasAgent = false;

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? '');
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = explode(':', $line, 2);
            $field = strtolower(trim($field));
            $value = trim($value);

            if ($field === 'user-agent') {
                if (!$lastWasAgent) {
                    $current = [];
                }
                $current[] = strtolower($value);
                $lastWasAgent = true;
                continue;
            }
            $lastWasAgent = false;

            if ($field === 'sitemap') {
                if (Url::isHttp($value)) {
                    $r->sitemaps[] = Url::normalize($value);
                }
                continue;
            }
            if ($current === []) {
                continue;
            }
            foreach ($current as $agent) {
                if ($field === 'disallow') {
                    $groups[$agent][] = [false, $value];
                } elseif ($field === 'allow') {
                    $groups[$agent][] = [true, $value];
                } elseif ($field === 'crawl-delay') {
                    $delays[$agent] = (float) str_replace(',', '.', $value);
                }
            }
        }

        // Prefer a block naming us, then any block whose agent token we contain, then '*'.
        $pick = null;
        foreach (array_keys($groups) as $agent) {
            if ($agent === $ourAgent || ($agent !== '*' && $agent !== '' && str_contains($ourAgent, $agent))) {
                $pick = $agent;
                break;
            }
        }
        if ($pick === null) {
            $pick = isset($groups['*']) ? '*' : null;
        }

        if ($pick !== null) {
            foreach ($groups[$pick] as [$allow, $path]) {
                if ($path === '') {
                    // "Disallow:" with no value means allow everything.
                    if (!$allow) {
                        continue;
                    }
                }
                $r->rules[] = ['allow' => $allow, 're' => self::toRegex($path), 'len' => strlen($path)];
            }
            $r->delay = (float) ($delays[$pick] ?? $delays['*'] ?? 0.0);
        } else {
            $r->delay = (float) ($delays['*'] ?? 0.0);
        }

        return $r;
    }

    private static function toRegex(string $pattern): string
    {
        $anchorEnd = str_ends_with($pattern, '$');
        if ($anchorEnd) {
            $pattern = substr($pattern, 0, -1);
        }
        $re = '';
        foreach (str_split($pattern) as $chr) {
            $re .= $chr === '*' ? '.*' : preg_quote($chr, '#');
        }
        return '#^' . $re . ($anchorEnd ? '$' : '') . '#';
    }

    /** Longest matching rule wins; Allow beats Disallow at equal length. */
    public function allows(string $url): bool
    {
        if (!$this->loaded || $this->rules === []) {
            return true;
        }
        $path = Url::path($url);
        $q = parse_url($url, PHP_URL_QUERY);
        if ($q) {
            $path .= '?' . $q;
        }

        $bestLen = -1;
        $verdict = true;
        foreach ($this->rules as $rule) {
            if (preg_match($rule['re'], $path) === 1) {
                if ($rule['len'] > $bestLen || ($rule['len'] === $bestLen && $rule['allow'])) {
                    $bestLen = $rule['len'];
                    $verdict = $rule['allow'];
                }
            }
        }
        return $verdict;
    }

    public function crawlDelay(): float
    {
        return max(0.0, min(30.0, $this->delay));
    }

    /** @return string[] */
    public function sitemaps(): array
    {
        return array_values(array_unique($this->sitemaps));
    }

    public function hasRules(): bool
    {
        return $this->rules !== [];
    }

    public function toArray(): array
    {
        return ['rules' => $this->rules, 'delay' => $this->delay,
                'sitemaps' => $this->sitemaps, 'loaded' => $this->loaded];
    }

    public static function fromArray(array $a): self
    {
        $r = new self();
        $r->rules    = $a['rules'] ?? [];
        $r->delay    = (float) ($a['delay'] ?? 0);
        $r->sitemaps = $a['sitemaps'] ?? [];
        $r->loaded   = (bool) ($a['loaded'] ?? false);
        return $r;
    }
}
