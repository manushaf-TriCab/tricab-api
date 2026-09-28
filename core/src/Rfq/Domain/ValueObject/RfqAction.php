<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\ValueObject;

/**
 * The actions an actor may take on one RFQ, as the server tells the browser.
 *
 * The browser renders buttons from `allowedActions` on each record and from
 * nothing else — not from role names, not from a client-side permission table
 * (the scaffold's `roles.ts`). Each action still re-checks on the server; this
 * list only saves a person clicking something that would refuse them.
 * Ported from the source `allowed_draft_actions`, with `push` renamed `sync`
 * and `reconcile` and `purge` added.
 */
final class RfqAction extends Vocabulary
{
    public const VIEW = 'view';
    public const COMMENT = 'comment';
    public const CLAIM = 'claim';
    public const ASSIGN = 'assign';
    public const WORKFLOW = 'workflow';
    public const EDIT = 'edit';
    public const SYNC = 'sync';
    public const RECONCILE = 'reconcile';
    public const ARCHIVE = 'archive';
    public const RESTORE = 'restore';
    public const PURGE = 'purge';

    public static function values(): array
    {
        return [
            self::VIEW,
            self::COMMENT,
            self::CLAIM,
            self::ASSIGN,
            self::WORKFLOW,
            self::EDIT,
            self::SYNC,
            self::RECONCILE,
            self::ARCHIVE,
            self::RESTORE,
            self::PURGE,
        ];
    }

    protected static function fallback(): string
    {
        return self::VIEW;
    }

    protected static function label(): string
    {
        return 'RFQ action';
    }
}
