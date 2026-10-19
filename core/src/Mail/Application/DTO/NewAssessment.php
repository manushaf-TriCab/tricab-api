<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\DTO;

use DateTimeImmutable;
use TriCab\Core\Mail\Domain\Exception\MailInputInvalid;
use TriCab\Core\Mail\Domain\MailVocabulary;

/**
 * One classifier result, or one pre-filter skip. Build it with {@see classified()} or
 * {@see skipped()}. A skip never ran the model: its label is `not_rfq` with full confidence,
 * and its `reason` is the skip reason, which is what the screen shows.
 */
final readonly class NewAssessment
{
    private function __construct(
        public string $label,
        public float $confidence,
        public string $reason,
        public string $model,
        public ?int $latencyMs,
        public string $version,
        public bool $skipped,
        public ?string $skipReason,
        public bool $injectionSuspected,
        public DateTimeImmutable $assessedAt,
    ) {
    }

    public static function classified(
        string $label,
        float $confidence,
        string $reason,
        string $model,
        string $version,
        DateTimeImmutable $assessedAt,
        ?int $latencyMs = null,
        bool $injectionSuspected = false,
    ): self {
        if (!MailVocabulary::isLabel($label)) {
            throw new MailInputInvalid('label must be one of: ' . implode(', ', MailVocabulary::LABELS) . '.');
        }
        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new MailInputInvalid('confidence must be between 0 and 1.');
        }
        if ($latencyMs !== null && $latencyMs < 0) {
            throw new MailInputInvalid('latencyMs cannot be negative.');
        }

        return new self($label, $confidence, mb_substr(trim($reason), 0, 1000), $model, $latencyMs, $version, false, null, $injectionSuspected, $assessedAt);
    }

    public static function skipped(string $skipReason, string $version, DateTimeImmutable $assessedAt, string $model = 'pre-filter'): self
    {
        $skipReason = mb_substr(trim($skipReason), 0, 200);
        if ($skipReason === '') {
            throw new MailInputInvalid('A skipped message needs the reason it was skipped.');
        }

        return new self(MailVocabulary::LABEL_NOT_RFQ, 1.0, $skipReason, $model, null, $version, true, $skipReason, false, $assessedAt);
    }
}
