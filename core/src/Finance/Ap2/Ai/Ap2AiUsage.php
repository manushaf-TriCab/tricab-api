<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Ai;

/**
 * What one model call (or one Copilot turn, summed over its rounds) used and cost. Logged for every call, failed
 * ones too, so the cost per invoice can be measured before scheduled sync is turned on. Never carries mail text.
 */
final readonly class Ap2AiUsage
{
    public const PURPOSE_CLASSIFY = 'ap2_mail_classify';
    public const PURPOSE_EXTRACT = 'ap2_invoice_extract';
    public const PURPOSE_DRAFT = 'ap2_reply_draft';
    public const PURPOSE_COPILOT = 'ap2_copilot';

    public function __construct(
        public string $purpose,
        public string $model,
        public string $promptVersion,
        public int $inputTokens,
        public int $outputTokens,
        public int $cacheReadTokens,
        public int $cacheWriteTokens,
        public float $costUsd,
        public int $durationMs,
        public int $calls,
        public bool $ok,
    ) {
    }

    /** A call that made no model request (a sender match, a refused file): nothing used. */
    public static function none(string $purpose, string $model, string $promptVersion): self
    {
        return new self($purpose, $model, $promptVersion, 0, 0, 0, 0, 0.0, 0, 0, true);
    }

    public function plus(self $other): self
    {
        return new self(
            $this->purpose,
            $other->model !== '' ? $other->model : $this->model,
            $this->promptVersion,
            $this->inputTokens + $other->inputTokens,
            $this->outputTokens + $other->outputTokens,
            $this->cacheReadTokens + $other->cacheReadTokens,
            $this->cacheWriteTokens + $other->cacheWriteTokens,
            round($this->costUsd + $other->costUsd, 6),
            $this->durationMs + $other->durationMs,
            $this->calls + $other->calls,
            $this->ok && $other->ok,
        );
    }

    public function failed(): self
    {
        return new self($this->purpose, $this->model, $this->promptVersion, $this->inputTokens, $this->outputTokens, $this->cacheReadTokens, $this->cacheWriteTokens, $this->costUsd, $this->durationMs, $this->calls, false);
    }

    /** @return array<string, string|int|float|bool> */
    public function toArray(): array
    {
        return [
            'purpose' => $this->purpose,
            'model' => $this->model,
            'prompt' => $this->promptVersion,
            'inputTokens' => $this->inputTokens,
            'outputTokens' => $this->outputTokens,
            'cacheReadTokens' => $this->cacheReadTokens,
            'cacheWriteTokens' => $this->cacheWriteTokens,
            'costUsd' => $this->costUsd,
            'durationMs' => $this->durationMs,
            'calls' => $this->calls,
            'ok' => $this->ok,
        ];
    }
}
