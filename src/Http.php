<?php
declare(strict_types=1);

/**
 * cURL based fetcher. Downloads several URLs at once with curl_multi and
 * aborts any response that grows past the per-file limit.
 */
final class Http
{
    private array $opt;

    public function __construct(array $opt = [])
    {
        $this->opt = $opt + [
            'user_agent'  => (string) sg_config('user_agent'),
            'timeout'     => 25,
            'connect'     => 12,
            'max_bytes'   => 25 * 1024 * 1024,
            'verify_ssl'  => false,
            'cookie'      => '',
            'auth_user'   => '',
            'auth_pass'   => '',
            'referer'     => '',
        ];
        if (trim((string) $this->opt['user_agent']) === '') {
            $this->opt['user_agent'] = (string) sg_config('user_agent');
        }
    }

    /**
     * Fetch one URL.
     * @return array{url:string,final_url:string,status:int,ctype:string,body:string,size:int,error:string,headers:array,ms:int}
     */
    public function get(string $url, bool $headOnly = false): array
    {
        $out = [];
        $this->getMany([$url], static function (array $r) use (&$out): void {
            $out = $r;
        }, $headOnly);
        return $out ?: self::fail($url, 'no result');
    }

    /**
     * What this server can actually do. Shared hosts often disable the
     * curl_multi_* family (or all of cURL) through disable_functions, so the
     * capability is detected once and the fetcher degrades instead of crashing.
     *
     * @return string 'multi' | 'single' | 'stream' | 'none'
     */
    public static function mode(): string
    {
        static $mode = null;
        if ($mode !== null) {
            return $mode;
        }

        // Escape hatch: config.php can pin the mode if detection guesses wrong.
        $forced = (string) sg_config('force_http_mode', '');
        if (in_array($forced, ['multi', 'single', 'stream'], true)) {
            $mode = $forced;
            return $mode;
        }

        $single = function_exists('curl_init') && function_exists('curl_exec')
               && function_exists('curl_setopt_array') && function_exists('curl_getinfo');

        $multi = $single
              && function_exists('curl_multi_init') && function_exists('curl_multi_exec')
              && function_exists('curl_multi_add_handle') && function_exists('curl_multi_select')
              && function_exists('curl_multi_info_read') && function_exists('curl_multi_remove_handle')
              && function_exists('curl_multi_close');

        if ($multi) {
            $mode = 'multi';
        } elseif ($single) {
            $mode = 'single';
        } elseif (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            $mode = 'stream';
        } else {
            $mode = 'none';
        }
        return $mode;
    }

    /** Human readable note for the activity log. */
    public static function modeNote(): string
    {
        return match (self::mode()) {
            'multi'  => '',
            'single' => 'This server has the parallel cURL functions disabled, so files are '
                      . 'fetched one at a time. It still works, just slower.',
            'stream' => 'cURL is not available on this server, so downloads use PHP streams. '
                      . 'Slower, and HTTP authentication is not supported.',
            default  => '',
        };
    }

    /**
     * Fetch many URLs, in parallel where the server allows it.
     * $onDone is called once per finished request.
     */
    public function getMany(array $urls, callable $onDone, bool $headOnly = false): void
    {
        $urls = array_values(array_unique(array_filter($urls)));
        if (!$urls) {
            return;
        }

        switch (self::mode()) {
            case 'multi':
                $this->getManyParallel($urls, $onDone, $headOnly);
                return;

            case 'single':
                foreach ($urls as $u) {
                    $onDone($this->getOneCurl($u, $headOnly));
                }
                return;

            case 'stream':
                foreach ($urls as $u) {
                    $onDone($this->getOneStream($u, $headOnly));
                }
                return;

            default:
                throw new RuntimeException(
                    'This server cannot make outgoing web requests: cURL is disabled and '
                    . 'allow_url_fopen is off. Ask your host to enable the cURL extension, '
                    . 'or run the tool somewhere you control (for example XAMPP on your own PC).'
                );
        }
    }

    /** One handle at a time, for hosts with curl_multi_* disabled. */
    private function getOneCurl(string $url, bool $headOnly): array
    {
        [$ch, $state] = $this->prepare($url, $headOnly);
        curl_exec($ch);
        $errno = function_exists('curl_errno') ? (int) curl_errno($ch) : 0;
        return $this->result($ch, $url, $state, $errno);
    }

    private function getManyParallel(array $urls, callable $onDone, bool $headOnly): void
    {
        $mh = curl_multi_init();
        $slots = [];   // spl_object_id => ['ch'=>handle,'url'=>string,'state'=>array]

        foreach ($urls as $u) {
            [$ch, $state] = $this->prepare($u, $headOnly);
            curl_multi_add_handle($mh, $ch);
            $slots[spl_object_id($ch)] = ['ch' => $ch, 'url' => $u, 'state' => $state];
        }

        do {
            $code = curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 0.4);
            }

            while ($info = curl_multi_info_read($mh)) {
                $ch  = $info['handle'];
                $id  = spl_object_id($ch);
                $row = $slots[$id] ?? null;
                if ($row === null) {
                    curl_multi_remove_handle($mh, $ch);
                    continue;
                }
                $onDone($this->result($ch, $row['url'], $row['state'], $info['result']));
                curl_multi_remove_handle($mh, $ch);
                // PHP 8 frees the handle automatically.
                unset($slots[$id]);
            }
        } while ($running > 0 && $code === CURLM_OK);

        // Anything still open (multi error) is reported as a failure.
        foreach ($slots as $row) {
            $onDone(self::fail($row['url'], 'connection dropped'));
            curl_multi_remove_handle($mh, $row['ch']);
            // handle freed on scope exit
        }
        curl_multi_close($mh);
    }

    /**
     * Last resort: PHP stream wrappers, for hosts with cURL fully disabled.
     * ignore_errors keeps the body of a 404 so the crawler can log it properly.
     */
    private function getOneStream(string $url, bool $headOnly): array
    {
        $start = microtime(true);
        $max = (int) $this->opt['max_bytes'];

        $headers = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
        ];
        if (($this->opt['cookie'] ?? '') !== '') {
            $headers[] = 'Cookie: ' . $this->opt['cookie'];
        }
        if (($this->opt['auth_user'] ?? '') !== '') {
            $headers[] = 'Authorization: Basic '
                . base64_encode($this->opt['auth_user'] . ':' . $this->opt['auth_pass']);
        }

        $ctx = stream_context_create([
            'http' => [
                'method'           => $headOnly ? 'HEAD' : 'GET',
                'user_agent'       => (string) $this->opt['user_agent'],
                'header'           => implode("\r\n", $headers),
                'follow_location'  => 1,
                'max_redirects'    => 7,
                'timeout'          => (float) $this->opt['timeout'],
                'ignore_errors'    => true,
                'protocol_version' => 1.1,
            ],
            'ssl' => [
                'verify_peer'       => (bool) $this->opt['verify_ssl'],
                'verify_peer_name'  => (bool) $this->opt['verify_ssl'],
                'SNI_enabled'       => true,
            ],
        ]);

        $fh = @fopen($url, 'rb', false, $ctx);
        if (!$fh) {
            $e = error_get_last();
            return self::fail($url, trim((string) ($e['message'] ?? 'connection failed')));
        }

        $meta = stream_get_meta_data($fh);
        $body = '';
        $tooBig = false;
        if (!$headOnly) {
            while (!feof($fh)) {
                $chunk = fread($fh, 131072);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $body .= $chunk;
                if (strlen($body) > $max) {
                    $tooBig = true;
                    $body = '';
                    break;
                }
            }
        }
        fclose($fh);

        // wrapper_data holds every hop; the last status line is the real one.
        $status = 0;
        $parsed = [];
        $location = '';
        foreach ((array) ($meta['wrapper_data'] ?? []) as $line) {
            $line = (string) $line;
            if (stripos($line, 'HTTP/') === 0) {
                if (preg_match('#\s(\d{3})\s#', $line . ' ', $m)) {
                    $status = (int) $m[1];
                }
                $parsed = [];
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $name = strtolower(substr($line, 0, $pos));
                $parsed[$name] = trim(substr($line, $pos + 1));
                if ($name === 'location') {
                    $location = $parsed[$name];
                }
            }
        }

        $final = $url;
        if ($location !== '') {
            $resolved = Url::resolve($url, $location);
            if ($resolved !== null && $resolved !== '') {
                $final = $resolved;
            }
        }

        $error = '';
        if ($tooBig) {
            $error = 'skipped: larger than the per-file limit';
        } elseif ($status === 0) {
            $error = 'no response';
        } elseif ($status >= 400) {
            $error = 'HTTP ' . $status;
        }

        return [
            'url'       => $url,
            'final_url' => Url::normalize($final) ?: $final,
            'status'    => $status,
            'ctype'     => (string) ($parsed['content-type'] ?? ''),
            'body'      => $body,
            'size'      => strlen($body),
            'error'     => $error,
            'headers'   => $parsed,
            'ms'        => (int) round((microtime(true) - $start) * 1000),
        ];
    }

    /**
     * Build one handle. The state is an object on purpose: the write/header
     * callbacks and the caller must all see the same instance.
     * @return array{0:CurlHandle,1:stdClass}
     */
    private function prepare(string $url, bool $headOnly): array
    {
        $state = new stdClass();
        $state->body    = '';
        $state->headers = [];
        $state->too_big = false;
        $state->start   = microtime(true);
        $max = (int) $this->opt['max_bytes'];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 6,
            CURLOPT_TIMEOUT        => (int) $this->opt['timeout'],
            CURLOPT_CONNECTTIMEOUT => (int) $this->opt['connect'],
            CURLOPT_USERAGENT      => (string) $this->opt['user_agent'],
            CURLOPT_ENCODING       => '',
            CURLOPT_SSL_VERIFYPEER => (bool) $this->opt['verify_ssl'],
            CURLOPT_SSL_VERIFYHOST => $this->opt['verify_ssl'] ? 2 : 0,
            CURLOPT_AUTOREFERER    => true,
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
            ],
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use ($state): int {
                $len = strlen($line);
                $t = trim($line);
                if ($t === '') {
                    return $len;
                }
                if (stripos($t, 'HTTP/') === 0) {
                    $state->headers = [];        // reset on each redirect hop
                    return $len;
                }
                $pos = strpos($t, ':');
                if ($pos !== false) {
                    $state->headers[strtolower(substr($t, 0, $pos))] = trim(substr($t, $pos + 1));
                }
                return $len;
            },
        ]);

        if ($headOnly) {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        } else {
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($handle, string $chunk) use ($state, $max): int {
                $state->body .= $chunk;
                if (strlen($state->body) > $max) {
                    $state->too_big = true;
                    return 0;   // returning 0 aborts the transfer
                }
                return strlen($chunk);
            });
        }

        if (($this->opt['cookie'] ?? '') !== '') {
            curl_setopt($ch, CURLOPT_COOKIE, (string) $this->opt['cookie']);
        }
        if (($this->opt['auth_user'] ?? '') !== '') {
            curl_setopt($ch, CURLOPT_USERPWD, $this->opt['auth_user'] . ':' . $this->opt['auth_pass']);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
        }
        if (($this->opt['referer'] ?? '') !== '') {
            curl_setopt($ch, CURLOPT_REFERER, (string) $this->opt['referer']);
        }

        return [$ch, $state];
    }

    private function result($ch, string $url, stdClass $state, int $curlResult): array
    {
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $final  = (string) (curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url);
        $ctype  = (string) ($state->headers['content-type'] ?? curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?? '');
        $body   = (string) $state->body;

        $error = '';
        if ($state->too_big) {
            $error = 'skipped: larger than the per-file limit';
            $body = '';
        } elseif ($curlResult !== CURLE_OK) {
            $error = function_exists('curl_strerror')
                ? (curl_strerror($curlResult) ?: 'curl error ' . $curlResult)
                : 'curl error ' . $curlResult;
            $e = function_exists('curl_error') ? (string) curl_error($ch) : '';
            if ($e !== '') {
                $error = $e;
            }
        } elseif ($status >= 400) {
            $error = 'HTTP ' . $status;
        }

        return [
            'url'       => $url,
            'final_url' => Url::normalize($final) ?: $final,
            'status'    => $status,
            'ctype'     => $ctype,
            'body'      => $body,
            'size'      => strlen($body),
            'error'     => $error,
            'headers'   => $state->headers,
            'ms'        => (int) round((microtime(true) - (float) $state->start) * 1000),
        ];
    }

    private static function fail(string $url, string $msg): array
    {
        return ['url' => $url, 'final_url' => $url, 'status' => 0, 'ctype' => '', 'body' => '',
                'size' => 0, 'error' => $msg, 'headers' => [], 'ms' => 0];
    }
}
