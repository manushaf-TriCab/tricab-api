<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Access;

use InvalidArgumentException;
use TriCab\Core\Finance\Shared\Access\FinanceAccessScope;
use TriCab\Core\Finance\Shared\Access\FinanceAccessScopeReader;

/** The scopes held in memory: the tests', and a database-less run's (where nobody has one). */
final class InMemoryFinanceAccessScopeReader implements FinanceAccessScopeReader
{
    /** @var array<string, array{branches: list<string>, mailboxes: list<string>}> */
    private array $rows = [];

    public function grant(int $userId, string $application, string $type, string $value): void
    {
        if (!in_array($type, FinanceAccessScope::TYPES, true)) {
            throw new InvalidArgumentException('A scope is a branch or a mailbox.');
        }
        $value = FinanceAccessScope::normalise($type, $value);
        if ($value === '') {
            throw new InvalidArgumentException('A scope value is required.');
        }
        $key = $userId . '|' . $application;
        $list = $type === FinanceAccessScope::TYPE_BRANCH ? 'branches' : 'mailboxes';
        $this->rows[$key] ??= ['branches' => [], 'mailboxes' => []];
        if (!in_array($value, $this->rows[$key][$list], true)) {
            $this->rows[$key][$list][] = $value;
            sort($this->rows[$key][$list]);
        }
    }

    public function forUser(int $userId, string $application): FinanceAccessScope
    {
        $row = $this->rows[$userId . '|' . $application] ?? ['branches' => [], 'mailboxes' => []];

        return new FinanceAccessScope($userId, $application, $row['branches'], $row['mailboxes']);
    }
}
