<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Mail;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Finance\Shared\Mail\FinanceMailbox;
use TriCab\Core\Finance\Shared\Mail\FinanceMailboxRegistry;

/** `finance.mailboxes` and `finance.mailbox_grants` (`create-finance-schema.sql`). Read-only. */
final class AzureSqlFinanceMailboxRegistry implements FinanceMailboxRegistry
{
    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function find(string $address): ?FinanceMailbox
    {
        $rows = $this->connection->fetchAll(
            'SELECT [id], [email], [display_name], [purpose], [is_active] FROM [finance].[mailboxes] WHERE [email] = :email',
            ['email' => strtolower(trim($address))],
        );
        if ($rows === []) {
            return null;
        }

        return $this->hydrate($rows, $this->grantsFor([AzureSqlValueMapper::stringValue($rows[0]['id'])]))[0];
    }

    public function all(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT [id], [email], [display_name], [purpose], [is_active] FROM [finance].[mailboxes] ORDER BY [email]',
        );

        return $this->hydrate($rows, $this->grantsFor(array_map(
            static fn (array $row): string => AzureSqlValueMapper::stringValue($row['id']),
            $rows,
        )));
    }

    /**
     * @param list<string> $ids
     * @return array<string, array<string, array{read: bool, send: bool}>> by mailbox id, then app key
     */
    private function grantsFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $params = [];
        $marks = [];
        foreach ($ids as $i => $id) {
            $params['id' . $i] = $id;
            $marks[] = ':id' . $i;
        }
        $rows = $this->connection->fetchAll(
            'SELECT [mailbox_id], [application_key], [can_read], [can_send] FROM [finance].[mailbox_grants]
             WHERE [mailbox_id] IN (' . implode(', ', $marks) . ') ORDER BY [application_key]',
            $params,
        );
        $grants = [];
        foreach ($rows as $row) {
            $grants[AzureSqlValueMapper::stringValue($row['mailbox_id'])][AzureSqlValueMapper::stringValue($row['application_key'])] = [
                'read' => AzureSqlValueMapper::boolValue($row['can_read']),
                'send' => AzureSqlValueMapper::boolValue($row['can_send']),
            ];
        }

        return $grants;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, array<string, array{read: bool, send: bool}>> $grants
     * @return list<FinanceMailbox>
     */
    private function hydrate(array $rows, array $grants): array
    {
        $out = [];
        foreach ($rows as $row) {
            $id = AzureSqlValueMapper::stringValue($row['id']);
            $out[] = new FinanceMailbox(
                $id,
                strtolower(AzureSqlValueMapper::stringValue($row['email'])),
                AzureSqlValueMapper::stringValue($row['display_name']),
                AzureSqlValueMapper::stringValue($row['purpose']),
                AzureSqlValueMapper::boolValue($row['is_active']),
                $grants[$id] ?? [],
            );
        }

        return $out;
    }
}
