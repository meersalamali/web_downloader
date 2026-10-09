<?php
declare(strict_types=1);

/**
 * Pure-PHP ZIP writer. Needs only zlib, so it works even when the
 * php_zip extension is disabled (which it is in a default XAMPP install).
 *
 * It is resumable on purpose: the archive is written entry by entry and the
 * central directory is only appended by finish(). State can be serialised to
 * JSON between requests, which lets a big site be zipped over several ticks.
 */
final class ZipWriter
{
    private const SIG_LOCAL   = 0x04034b50;
    private const SIG_CENTRAL = 0x02014b50;
    private const SIG_EOCD    = 0x06054b50;
    private const CHUNK       = 262144;

    /** Already-compressed formats: storing them is much faster and just as small. */
    private const STORE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'ico', 'mp3', 'mp4',
                               'webm', 'ogg', 'oga', 'ogv', 'm4a', 'm4v', 'mov', 'avi', 'mkv',
                               'zip', 'gz', 'rar', '7z', 'woff', 'woff2', 'pdf'];

    private string $path;
    /** @var resource */
    private $fh;
    private int $offset = 0;
    /** @var array<int,array<string,mixed>> */
    private array $entries = [];
    private int $level;

    private function __construct(string $path, int $level)
    {
        $this->path  = $path;
        $this->level = Util::clamp($level, 0, 9);
    }

    public static function create(string $path, int $level = 6): self
    {
        Util::ensureDir(dirname($path));
        $z = new self($path, $level);
        $fh = @fopen($path, 'w+b');
        if (!$fh) {
            throw new RuntimeException('Cannot create archive: ' . $path);
        }
        $z->fh = $fh;
        return $z;
    }

    /** Reopen an archive that a previous tick started. */
    public static function resume(string $path, array $state, int $level = 6): self
    {
        $z = new self($path, $level);
        $fh = @fopen($path, 'r+b');
        if (!$fh) {
            throw new RuntimeException('Cannot reopen archive: ' . $path);
        }
        $z->fh      = $fh;
        $z->offset  = (int) ($state['offset'] ?? 0);
        $z->entries = $state['entries'] ?? [];
        fseek($fh, $z->offset);
        return $z;
    }

    public function state(): array
    {
        return ['offset' => $this->offset, 'entries' => $this->entries];
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /** Add a file from disk. $name is the path inside the archive. */
    public function addFile(string $file, string $name): bool
    {
        $in = @fopen($file, 'rb');
        if (!$in) {
            return false;
        }
        $name = $this->cleanName($name);
        $size = (int) @filesize($file);
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $store = $this->level === 0 || ($size > 1024 && in_array($ext, self::STORE_EXT, true));

        $headerAt = $this->offset;
        $mtime = (int) (@filemtime($file) ?: time());

        $this->writeLocalHeader($name, $store ? 0 : 8, $mtime);

        $crcCtx = hash_init('crc32b');
        $usize = 0;
        $csize = 0;

        if ($store) {
            while (!feof($in)) {
                $chunk = fread($in, self::CHUNK);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                hash_update($crcCtx, $chunk);
                $usize += strlen($chunk);
                $csize += $this->write($chunk);
            }
        } else {
            $ctx = deflate_init(ZLIB_ENCODING_RAW, ['level' => $this->level]);
            while (!feof($in)) {
                $chunk = fread($in, self::CHUNK);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                hash_update($crcCtx, $chunk);
                $usize += strlen($chunk);
                $out = deflate_add($ctx, $chunk, ZLIB_NO_FLUSH);
                if ($out !== '') {
                    $csize += $this->write($out);
                }
            }
            $out = deflate_add($ctx, '', ZLIB_FINISH);
            if ($out !== '') {
                $csize += $this->write($out);
            }
        }
        fclose($in);

        $crc = (int) hexdec(hash_final($crcCtx));

        // Sizes are only known now, so patch them into the local header.
        $end = $this->offset;
        fseek($this->fh, $headerAt + 14);
        fwrite($this->fh, pack('VVV', $crc, $csize, $usize));
        fseek($this->fh, $end);

        $this->entries[] = [
            'name'   => $name,
            'method' => $store ? 0 : 8,
            'crc'    => $crc,
            'csize'  => $csize,
            'usize'  => $usize,
            'offset' => $headerAt,
            'mtime'  => $mtime,
        ];
        return true;
    }

    /** Add a file whose contents are already in memory. */
    public function addString(string $content, string $name): void
    {
        $name = $this->cleanName($name);
        $headerAt = $this->offset;
        $mtime = time();
        $deflated = $this->level === 0 ? $content : (string) gzdeflate($content, $this->level);
        $store = $this->level === 0 || strlen($deflated) >= strlen($content);

        $this->writeLocalHeader($name, $store ? 0 : 8, $mtime);
        $payload = $store ? $content : $deflated;
        $csize = $this->write($payload);
        $crc = crc32($content);

        $end = $this->offset;
        fseek($this->fh, $headerAt + 14);
        fwrite($this->fh, pack('VVV', $crc, $csize, strlen($content)));
        fseek($this->fh, $end);

        $this->entries[] = [
            'name' => $name, 'method' => $store ? 0 : 8, 'crc' => $crc,
            'csize' => $csize, 'usize' => strlen($content),
            'offset' => $headerAt, 'mtime' => $mtime,
        ];
    }

    /** Write the central directory and close. Returns the archive size in bytes. */
    public function finish(): int
    {
        $cdOffset = $this->offset;
        foreach ($this->entries as $e) {
            [$time, $date] = self::dosTime((int) $e['mtime']);
            $name = (string) $e['name'];
            $this->write(pack(
                'VvvvvvvVVVvvvvvVV',
                self::SIG_CENTRAL,
                0x031E,              // version made by (unix, zip 3.0)
                20,                  // version needed
                0x0800,              // UTF-8 names
                (int) $e['method'],
                $time,
                $date,
                (int) $e['crc'],
                (int) $e['csize'],
                (int) $e['usize'],
                strlen($name),
                0,                   // extra length
                0,                   // comment length
                0,                   // disk number start
                0,                   // internal attributes
                0x81A40000,          // external attributes: regular file, 0644
                (int) $e['offset']
            ));
            $this->write($name);
        }
        $cdSize = $this->offset - $cdOffset;
        $n = count($this->entries);

        $this->write(pack('VvvvvVVv', self::SIG_EOCD, 0, 0, $n, $n, $cdSize, $cdOffset, 0));

        $size = $this->offset;
        fflush($this->fh);
        fclose($this->fh);
        return $size;
    }

    public function close(): void
    {
        if (is_resource($this->fh)) {
            fflush($this->fh);
            fclose($this->fh);
        }
    }

    /** ZIP without Zip64 cannot describe more than this. */
    public function nearLimits(): bool
    {
        return count($this->entries) >= 65000 || $this->offset >= 0xFFFF0000;
    }

    private function writeLocalHeader(string $name, int $method, int $mtime): void
    {
        [$time, $date] = self::dosTime($mtime);
        $this->write(pack(
            'VvvvvvVVVvv',
            self::SIG_LOCAL,
            20,          // version needed
            0x0800,      // UTF-8 names
            $method,
            $time,
            $date,
            0,           // crc      - patched later
            0,           // csize    - patched later
            0,           // usize    - patched later
            strlen($name),
            0            // extra length
        ));
        $this->write($name);
    }

    private function write(string $data): int
    {
        $n = fwrite($this->fh, $data);
        if ($n === false) {
            throw new RuntimeException('Write to archive failed (disk full?)');
        }
        $this->offset += $n;
        return $n;
    }

    private function cleanName(string $name): string
    {
        $name = str_replace(chr(92), '/', $name);
        $name = preg_replace('#/{2,}#', '/', $name) ?? $name;
        return ltrim($name, '/');
    }

    /** @return array{0:int,1:int} [dosTime, dosDate] */
    private static function dosTime(int $ts): array
    {
        $d = getdate($ts);
        if ($d['year'] < 1980) {
            $d = getdate(315532800); // 1980-01-01
        }
        $time = ($d['hours'] << 11) | ($d['minutes'] << 5) | ($d['seconds'] >> 1);
        $date = (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday'];
        return [$time, $date];
    }
}
