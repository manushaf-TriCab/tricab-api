<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Domain;

use DateTimeImmutable;

final readonly class NewManagerRoute
{
    public function __construct(
        public string $branch,
        public NprPerson $manager,
        public bool $isDelegate,
        public DateTimeImmutable $validFrom,
        public ?DateTimeImmutable $validTo,
        public ?int $approvedByUserId,
    ) {
    }
}
