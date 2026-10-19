<?php

declare(strict_types=1);

namespace TriCab\Api\Npr\Mail;

use TriCab\Api\Config\Settings;
use TriCab\Core\Npr\Mail\NprMailWake;

/**
 * The retry sweep's wake-up, as files in a directory the web app and the
 * WebJob share (the same design as the RFQ reader, api #33):
 *
 * - `wake`: a send failed since the last pass;
 * - `pending`: a pass left retryable rows (written before the database is
 *   touched, so a pass that dies on a resuming database is retried);
 * - `checked` older than the idle check: a lost signal or a fresh deploy.
 *
 * With none of them, a pass exits without touching the database, so the
 * serverless `tc` can stay paused.
 */
final class FileNprMailWake implements NprMailWake
{
    private const WAKE = 'wake';
    private const PENDING = 'pending';
    private const CHECKED = 'checked';

    public function __construct(private readonly string $directory)
    {
    }

    public static function fromSettings(Settings $settings): self
    {
        $path = trim($settings->nprMailWakePath);
        if ($path === '') {
            $path = 'var/npr-mail';
        }
        if (!str_starts_with($path, '/') && !(strlen($path) > 1 && ctype_alpha($path[0]) && $path[1] === ':')) {
            $path = dirname(__DIR__, 3) . '/' . ltrim($path, '/\\');
        }

        return new self(rtrim($path, '/\\'));
    }

    public function signal(): void
    {
        $this->touch(self::WAKE);
    }

    /** Whether this pass should go to the database; when it should, marks the work pending and consumes the wake-up. */
    public function beginPass(int $idleCheckMinutes): bool
    {
        clearstatcache();
        $checked = @filemtime($this->path(self::CHECKED));
        $due = $checked === false || $checked < time() - max(1, $idleCheckMinutes) * 60;
        if (!$due && !is_file($this->path(self::WAKE)) && !is_file($this->path(self::PENDING))) {
            return false;
        }
        $this->touch(self::PENDING);
        @unlink($this->path(self::WAKE));

        return true;
    }

    /** After a pass that reached the database: nothing outstanding means wait for a wake-up. */
    public function endPass(int $outstanding): void
    {
        $this->touch(self::CHECKED);
        if ($outstanding === 0) {
            @unlink($this->path(self::PENDING));
        }
    }

    private function touch(string $name): void
    {
        if ((!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory))
            || !@touch($this->path($name))) {
            error_log(sprintf('NPR mail: could not write %s in %s; the sweep falls back to its periodic check', $name, $this->directory));
        }
    }

    private function path(string $name): string
    {
        return $this->directory . '/' . $name;
    }
}
