<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\ValueObject;

/**
 * Who is changing an RFQ's workflow status, for `WorkflowTransitionPolicy`.
 *
 *  - `assignee` — the person the RFQ is assigned to, without `RFQ.DRAFT.ASSIGN`.
 *  - `manager`  — a holder of `RFQ.DRAFT.ASSIGN` with the RFQ in scope.
 *  - `system`   — the platform itself: claim, first edit, a succeeded sync.
 *
 * Resolved by the application service from the actor and the record. Nobody
 * outside those three may change workflow at all.
 */
final class WorkflowActor extends Vocabulary
{
    public const ASSIGNEE = 'assignee';
    public const MANAGER = 'manager';
    public const SYSTEM = 'system';

    public static function values(): array
    {
        return [self::ASSIGNEE, self::MANAGER, self::SYSTEM];
    }

    /** The actor with the fewest rights. */
    protected static function fallback(): string
    {
        return self::ASSIGNEE;
    }

    protected static function label(): string
    {
        return 'workflow actor';
    }

    public static function assignee(): self
    {
        return new self(self::ASSIGNEE);
    }

    public static function manager(): self
    {
        return new self(self::MANAGER);
    }

    public static function system(): self
    {
        return new self(self::SYSTEM);
    }
}
