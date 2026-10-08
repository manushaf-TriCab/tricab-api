<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Access;

/**
 * What one person may reach inside one finance app, for the two kinds of scope the
 * portal has (`finance.access_scopes`). Per kind: no values at all means
 * unrestricted, as in the portal; any values mean exactly those. This says nothing
 * about whether the person may enter the app (a role and a permission do that), and
 * an app that lets its administrators past the scope decides that itself.
 */
final readonly class FinanceAccessScope
{
    public const TYPE_BRANCH = 'branch';

    public const TYPE_MAILBOX = 'mailbox';

    public const TYPES = [self::TYPE_BRANCH, self::TYPE_MAILBOX];

    /**
     * @param list<string> $branches
     * @param list<string> $mailboxes
     */
    public function __construct(
        public int $userId,
        public string $application,
        public array $branches = [],
        public array $mailboxes = [],
    ) {
    }

    /** Value as stored and as compared: trimmed, and a mailbox address in lower case. */
    public static function normalise(string $type, string $value): string
    {
        $value = trim($value);

        return $type === self::TYPE_MAILBOX ? strtolower($value) : $value;
    }

    public function isUnrestricted(): bool
    {
        return $this->branches === [] && $this->mailboxes === [];
    }

    public function restrictsBranches(): bool
    {
        return $this->branches !== [];
    }

    public function restrictsMailboxes(): bool
    {
        return $this->mailboxes !== [];
    }

    public function allowsBranch(string $branch): bool
    {
        return $this->branches === [] || self::contains($this->branches, self::normalise(self::TYPE_BRANCH, $branch));
    }

    public function allowsMailbox(string $mailbox): bool
    {
        return $this->mailboxes === [] || in_array(self::normalise(self::TYPE_MAILBOX, $mailbox), $this->mailboxes, true);
    }

    /**
     * @param list<string> $values
     */
    private static function contains(array $values, string $needle): bool
    {
        foreach ($values as $value) {
            if (strcasecmp($value, $needle) === 0) {
                return true;
            }
        }

        return false;
    }
}
