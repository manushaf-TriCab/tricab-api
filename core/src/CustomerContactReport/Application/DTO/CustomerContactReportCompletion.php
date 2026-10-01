<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\DTO;

use DateTimeImmutable;

final readonly class CustomerContactReportCompletion
{
    public function __construct(
        public string $publicId,
        public string $expectedRowVersion,
        public int $actorUserId,
        public string $actorEmail,
        public bool $actionProvided,
        public ?string $assigneeActionTaken,
        public bool $findingsProvided,
        public ?string $assigneeFindings,
        public DateTimeImmutable $completedAtUtc,
    ) {
    }
}
