<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\DTO;

use DateTimeImmutable;

/** `MailLabelDecision`: a person marked the label right (`confirm`) or wrong (`correct`). */
final readonly class DecisionView
{
    public function __construct(
        public string $id,
        public string $assessmentId,
        public string $kind,
        public string $label,
        public MailParty $decidedBy,
        public DateTimeImmutable $decidedAt,
        public ?string $note,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'label' => $this->label,
            'decidedBy' => $this->decidedBy->toArray(),
            'decidedAt' => MailTime::iso($this->decidedAt),
            'note' => $this->note,
        ];
    }
}
