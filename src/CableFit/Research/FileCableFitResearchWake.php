<?php

declare(strict_types=1);

namespace TriCab\Api\CableFit\Research;

use TriCab\Api\Config\Settings;
use TriCab\Core\CableFit\Contract\CableFitResearchWake;

/**
 * The research WebJob's wake-up, as files in a directory the web app and the WebJob share (both run from
 * the same app root on the slot). The same behaviour as {@see \TriCab\Api\Rfq\Reader\FileRfqReaderWake},
 * in its own directory (`CABLEFIT_RESEARCH_WAKE_PATH`, default `var/cablefit-research`).
 *
 * The WebJob runs a pass every minute. Asking the database each time would keep the serverless `tc`
 * database from ever pausing, so a pass goes to the database only when one of these says there may be work:
 *
 * - `wake`: a request queued a run since the last pass.
 * - `pending`: a pass is under way, or the last one left runs unfinished (a retry, a claim abandoned by a
 *   crash). Written before the database is touched, so a pass that dies (the database still resuming)
 *   leaves it set and the next pass tries again. Cleared only by a pass that finds nothing outstanding.
 * - `checked` older than the idle check: a lost signal, or a fresh deploy (which empties the directory),
 *   is found by a periodic look.
 *
 * The run rows stay the record; these files only say when to look at them. The directory also holds
 * `pass.lock`, taken by the worker so two passes never overlap.
 */
final class FileCableFitResearchWake implements CableFitResearchWake
{
    private const WAKE = 'wake';
    private const PENDING = 'pending';
    private const CHECKED = 'checked';

    public function __construct(private readonly string $directory)
    {
    }

    public static function fromSettings(Settings $settings): self
    {
        $path = trim($settings->cablefitResearchWakePath);
        if ($path === '') {
            $path = 'var/cablefit-research';
        }
        if (!str_starts_with($path, '/') && !(strlen($path) > 1 && ctype_alpha($path[0]) && $path[1] === ':')) {
            $path = dirname(__DIR__, 3) . '/' . ltrim($path, '/\\');
        }

        return new self(rtrim($path, '/\\'));
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function signal(): void
    {
        $this->touch(self::WAKE);
    }

    /**
     * Whether this pass should go to the database. When it should, the work is marked pending and the
     * wake-up consumed, in that order and both before the database is read, so a run queued meanwhile is
     * either seen by this pass or signals the next one.
     */
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
            error_log(sprintf('CableFit research: could not write %s in %s; the worker falls back to its periodic check', $name, $this->directory));
        }
    }

    private function path(string $name): string
    {
        return $this->directory . '/' . $name;
    }
}
