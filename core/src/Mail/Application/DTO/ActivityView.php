<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\DTO;

use DateTimeImmutable;

/** `MailActivityEvent`. A null actor is the reader or the classifier and serialises as `system`. */
final readonly class ActivityView
{
    public function __construct(
        public string $id,
        public DateTimeImmutable $at,
        public ?MailParty $actor,
        public string $kind,
        public string $description,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'at' => MailTime::iso($this->at),
            'actor' => $this->actor === null ? 'system' : $this->actor->toArray(),
            'kind' => $this->kind,
            'description' => $this->description,
        ];
    }
}
