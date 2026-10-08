<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Access;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Finance\Shared\Access\FinanceAccessScope;
use TriCab\Core\Finance\Shared\Access\FinanceAccessScopeReader;

/** `finance.access_scopes` (`create-finance-access-schema.sql`). Read only. */
final class AzureSqlFinanceAccessScopeReader implements FinanceAccessScopeReader
{
    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function forUser(int $userId, string $application): FinanceAccessScope
    {
        $branches = [];
        $mailboxes = [];
        foreach ($this->connection->fetchAll(
            'SELECT [scope_type], [scope_value] FROM [finance].[access_scopes]
              WHERE [user_id] = :user AND [application_key] = :app ORDER BY [scope_type], [scope_value]',
            ['user' => $userId, 'app' => $application],
        ) as $row) {
            $type = AzureSqlValueMapper::stringValue($row['scope_type']);
            $value = FinanceAccessScope::normalise($type, AzureSqlValueMapper::stringValue($row['scope_value']));
            if ($type === FinanceAccessScope::TYPE_BRANCH) {
                $branches[] = $value;
            } elseif ($type === FinanceAccessScope::TYPE_MAILBOX) {
                $mailboxes[] = $value;
            }
        }

        return new FinanceAccessScope($userId, $application, $branches, $mailboxes);
    }
}
