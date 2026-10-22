<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\ValueObject;

use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;

final class UserManagementEventType
{
    public const USER_CREATED = 'USER_CREATED';
    public const USER_UPDATED = 'USER_UPDATED';
    public const USER_STATUS_CHANGED = 'USER_STATUS_CHANGED';
    public const USER_ROLE_ASSIGNED = 'USER_ROLE_ASSIGNED';
    public const USER_ROLE_REMOVED = 'USER_ROLE_REMOVED';
    public const ROLE_CREATED = 'ROLE_CREATED';
    public const ROLE_UPDATED = 'ROLE_UPDATED';
    public const ROLE_STATUS_CHANGED = 'ROLE_STATUS_CHANGED';
    public const ROLE_PERMISSION_ASSIGNED = 'ROLE_PERMISSION_ASSIGNED';
    public const ROLE_PERMISSION_REMOVED = 'ROLE_PERMISSION_REMOVED';
    public const PERMISSION_CREATED = 'PERMISSION_CREATED';
    public const PERMISSION_UPDATED = 'PERMISSION_UPDATED';
    public const ACT_AS_STARTED = 'ACT_AS_STARTED';
    public const ACT_AS_ENDED = 'ACT_AS_ENDED';

    private const ALLOWED = [
        self::USER_CREATED,
        self::USER_UPDATED,
        self::USER_STATUS_CHANGED,
        self::USER_ROLE_ASSIGNED,
        self::USER_ROLE_REMOVED,
        self::ROLE_CREATED,
        self::ROLE_UPDATED,
        self::ROLE_STATUS_CHANGED,
        self::ROLE_PERMISSION_ASSIGNED,
        self::ROLE_PERMISSION_REMOVED,
        self::PERMISSION_CREATED,
        self::PERMISSION_UPDATED,
        self::ACT_AS_STARTED,
        self::ACT_AS_ENDED,
    ];

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtoupper(trim($value));

        if (!in_array($normalized, self::ALLOWED, true)) {
            throw InvalidIdentityValue::for('UserManagementEventType', $value);
        }

        return new self($normalized);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
