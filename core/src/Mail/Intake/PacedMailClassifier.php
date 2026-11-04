<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Intake;

use Closure;

/**
 * The backfill's classifier: the real one, with a pause between calls and a longer back-off than the live
 * reader's single retry when the model answers 429 or 529 (Foundry Haiku has throttled bursts). A call that
 * is still throttled after {@see self::$maxRetries} retries throws {@see ClassifierThrottled} (the backfill then
 * defers the message instead of recording it `uncertain`), and `consecutiveGiveUps` lets the caller stop instead of recording a
 * long run of them.
 */
final class PacedMailClassifier implements MailClassifier
{
    public int $retries = 0;

    public int $consecutiveGiveUps = 0;

    private bool $first = true;

    /** @var Closure(float): void */
    private readonly Closure $sleep;

    /**
     * @param (callable(float): void)|null $sleep seconds; defaults to a real sleep
     */
    public function __construct(
        private readonly MailClassifier $inner,
        private readonly float $pauseSeconds = 1.0,
        private readonly int $maxRetries = 5,
        private readonly float $backoffBaseSeconds = 5.0,
        ?callable $sleep = null,
    ) {
        $this->sleep = $sleep === null
            ? static function (float $seconds): void {
                usleep((int) round($seconds * 1_000_000));
            }
        : Closure::fromCallable($sleep);
    }

    public function classify(ClassifierInput $input): ClassifierVerdict
    {
        if (!$this->first && $this->pauseSeconds > 0) {
            ($this->sleep)($this->pauseSeconds);
        }
        $this->first = false;

        for ($attempt = 0;; $attempt++) {
            try {
                $verdict = $this->inner->classify($input);
                $this->consecutiveGiveUps = 0;

                return $verdict;
            } catch (ClassifierFailed $failure) {
                if (!self::throttled($failure)) {
                    $this->consecutiveGiveUps = 0;

                    throw $failure;
                }
                if ($attempt >= $this->maxRetries) {
                    $this->consecutiveGiveUps++;

                    throw new ClassifierThrottled($failure->getMessage(), 0, $failure);
                }
                $this->retries++;
                ($this->sleep)($this->backoffBaseSeconds * (2 ** $attempt));
            }
        }
    }

    public function modelName(): string
    {
        return $this->inner->modelName();
    }

    public function promptVersion(): string
    {
        return $this->inner->promptVersion();
    }

    private static function throttled(ClassifierFailed $failure): bool
    {
        return str_contains($failure->getMessage(), 'HTTP 429') || str_contains($failure->getMessage(), 'HTTP 529');
    }
}
