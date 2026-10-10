<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Service;

use InvalidArgumentException;

/**
 * A ZIP archive built in memory, deflate-compressed, with names in UTF-8 (general-purpose flag bit 11). It needs only
 * `zlib` and `crc32`, so the invoice download does not depend on the `zip` extension being loaded. No ZIP64: the
 * download caps the total well below 4 GB.
 */
final class ApZipWriter
{
    /** @var list<string> local file records, in order */
    private array $locals = [];

    /** @var list<string> central directory records, in order */
    private array $central = [];

    private int $offset = 0;

    /**
     * @param int $timestamp Unix time stamped on every entry (DOS format, so 1980 to 2107)
     */
    public function __construct(private readonly int $timestamp)
    {
    }

    public function add(string $name, string $content): void
    {
        if ($name === '' || strlen($name) > 65535) {
            throw new InvalidArgumentException('A zip entry needs a name of up to 65535 bytes.');
        }
        $crc = crc32($content);
        $deflated = gzdeflate($content, 6);
        if ($deflated === false) {
            throw new InvalidArgumentException('The content could not be compressed.');
        }
        // Keep the smaller form, as a zip tool does.
        [$method, $data] = strlen($deflated) < strlen($content) ? [8, $deflated] : [0, $content];
        [$time, $date] = self::dos($this->timestamp);
        $flags = 0x0800;

        $local = pack('VvvvvvVVVvv', 0x04034b50, 20, $flags, $method, $time, $date, $crc, strlen($data), strlen($content), strlen($name), 0) . $name . $data;
        $this->central[] = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, $flags, $method, $time, $date, $crc, strlen($data), strlen($content), strlen($name), 0, 0, 0, 0, 0, $this->offset) . $name;
        $this->locals[] = $local;
        $this->offset += strlen($local);
    }

    public function bytes(): string
    {
        $directory = implode('', $this->central);
        $end = pack('VvvvvVVv', 0x06054b50, 0, 0, count($this->central), count($this->central), strlen($directory), $this->offset, 0);

        return implode('', $this->locals) . $directory . $end;
    }

    /**
     * @return array{0: int, 1: int} the DOS time and date words
     */
    private static function dos(int $timestamp): array
    {
        $parts = getdate(max($timestamp, 315532800));

        return [
            ($parts['hours'] << 11) | ($parts['minutes'] << 5) | intdiv($parts['seconds'], 2),
            ((max($parts['year'], 1980) - 1980) << 9) | ($parts['mon'] << 5) | $parts['mday'],
        ];
    }
}
