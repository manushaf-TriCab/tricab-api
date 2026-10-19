<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\DTO;

use DateTimeImmutable;

/**
 * `MailAssessment`: the classifier's result for the thread's latest classified message.
 * A pre-filter skip carries its reason in `reason`, as the contract has no separate field.
 */
final readonly class AssessmentView
{
    public function __construct(
        public string $id,
        public string $messageId,
        public string $label,
        public float $confidence,
        public string $reason,
        public string $model,
        public ?int $latencyMs,
        public string $version,
        public DateTimeImmutable $assessedAt,
        public bool $skipped,
        public bool $injectionSuspected,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'confidence' => $this->confidence,
            'reason' => $this->reason,
            'model' => $this->model,
            'latencyMs' => $this->latencyMs,
            'version' => $this->version,
            'assessedAt' => MailTime::iso($this->assessedAt),
            'skipped' => $this->skipped,
            'injectionSuspected' => $this->injectionSuspected,
        ];
    }
}
