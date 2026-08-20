<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Support;

final class ConflictDecision
{
    public const IGNORE = 'ignore';
    public const SKIP = 'skip';
    public const MERGE = 'merge';
    public const CREATE_NEW = 'create-new';
    public const REPLACE_EXISTING = 'replace-existing';
    public const USE_EXISTING = 'use-existing';
    public const LINK_CONTACT = 'link-contact';
    public const MANUAL_MAPPING = 'manual-mapping';

    public const STATUS_OPEN = 'open';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_IGNORED = 'ignored';
    public const STATUS_BLOCKED = 'blocked';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::IGNORE,
            self::SKIP,
            self::MERGE,
            self::CREATE_NEW,
            self::REPLACE_EXISTING,
            self::USE_EXISTING,
            self::LINK_CONTACT,
            self::MANUAL_MAPPING,
        ];
    }

    public static function statusForDecision(?string $decision): string
    {
        if ($decision === null || $decision === '') {
            return self::STATUS_OPEN;
        }
        if (in_array($decision, [self::IGNORE, self::SKIP], true)) {
            return self::STATUS_IGNORED;
        }

        return self::STATUS_RESOLVED;
    }
}
