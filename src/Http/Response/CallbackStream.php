<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Response;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A response body that does its work while it is being sent.
 *
 * Slim emits the status and headers, then reads the body until `eof()`. The
 * first `read()` runs the producer, which writes each piece straight out and
 * flushes it, so the client sees it at once instead of when the whole body is
 * ready. Middleware has already finished with the response by then, so its
 * headers (CORS and the rest) are on it as usual.
 *
 * The producer must not throw: by the time it runs the status line is gone,
 * so it reports failure inside the stream instead.
 */
final class CallbackStream implements StreamInterface
{
    private bool $started = false;

    private bool $finished = false;

    /** @param \Closure(callable(string): void): void $producer */
    public function __construct(
        private readonly \Closure $producer,
    ) {
    }

    /** Runs the producer against the client connection, flushing each piece. */
    public function read(int $length): string
    {
        if (!$this->started) {
            $this->prepareOutput();
            $this->drain(static function (string $chunk): void {
                echo $chunk;
                flush();
            });
        }
        $this->finished = true;

        return '';
    }

    /**
     * Runs the producer once, sending each piece to `$output`. `read()` uses it
     * with the connection; tests use it to see what the client would receive.
     *
     * @param callable(string): void $output
     */
    public function drain(callable $output): void
    {
        if ($this->started) {
            return;
        }
        $this->started = true;
        try {
            ($this->producer)($output);
        } finally {
            $this->finished = true;
        }
    }

    public function eof(): bool
    {
        return $this->finished;
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return 0;
    }

    /** The body is produced only by sending it; reading it as a string yields nothing. */
    public function __toString(): string
    {
        return '';
    }

    public function getContents(): string
    {
        return $this->read(0);
    }

    public function close(): void
    {
        $this->finished = true;
    }

    public function detach()
    {
        $this->finished = true;

        return null;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('A streamed response body cannot be sought.');
    }

    public function rewind(): void
    {
        throw new RuntimeException('A streamed response body cannot be rewound.');
    }

    public function write(string $string): int
    {
        throw new RuntimeException('A streamed response body is not writable.');
    }

    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }

    /**
     * Only for the real connection: PHP's own output buffer and compression would
     * otherwise hold the pieces back until the end.
     */
    private function prepareOutput(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        @ini_set('zlib.output_compression', '0');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
    }
}
