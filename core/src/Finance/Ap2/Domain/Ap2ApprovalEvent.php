<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Domain;

use DateTimeImmutable;

/** One line of an approval's own history (`finance.ap2_approval_events`): requested, approved, rejected, superseded. */
final readonly class Ap2ApprovalEvent
{
    public function __construct(
        public string $id,
        public string $approvalId,
        public string $action,
        public Ap2Person $by,
        public DateTimeImmutable $at,
        public string $comment = '',
    ) {
    }
}
