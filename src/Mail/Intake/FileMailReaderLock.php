<?php

declare(strict_types=1);

namespace TriCab\Api\Mail\Intake;

/**
 * One reader pass at a time per state directory (`var/rfq-mail/pass.lock`, an exclusive non-blocking
 * `flock`). The lock goes when the process does, so a crashed pass never strands it. The recorder's dedupe
 * is the second line: two passes that did overlap would still record each message once.
 */
final class FileMailReaderLock
{
    /** @param resource $handle */
    private function __construct(private $handle)
    {
    }

    /**
     * @return self|null null when another pass holds the lock
     *
     * @phpstan-impure
     */
    public static function acquire(string $directory): ?self
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return null;
        }
        $handle = fopen($directory . '/pass.lock', 'c');
        if ($handle === false) {
            return null;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return new self($handle);
    }

    public function release(): void
    {
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
    }
}
