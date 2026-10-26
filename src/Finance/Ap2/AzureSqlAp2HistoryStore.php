<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2;

use DateTimeImmutable;
use TriCab\Core\Finance\Ap2\Contract\Ap2HistoryStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2HistoryEntry;
use TriCab\Core\Finance\Ap2\Domain\Ap2Person;

/**
 * `finance.ap2_history`. APPEND ONLY: this class has an INSERT and a SELECT and must never get an UPDATE or a DELETE
 * (the table has a trigger that refuses them as well).
 */
final class AzureSqlAp2HistoryStore extends AzureSqlAp2Support implements Ap2HistoryStore
{
    public function append(Ap2HistoryEntry $e): void
    {
        $this->connection->execute(
            'INSERT INTO [finance].[ap2_history] ([id], [supplier_id], [invoice_id], [mail_id], [type], [detail], [actor_user_id], [actor_name], [actor_email], [at], [extra])
             VALUES (:id, :supplier, :invoice, :mail, :type, :detail, :actor, :name, :email, :at, :extra)',
            [
                'id' => $e->id,
                'supplier' => $e->supplierId,
                'invoice' => $e->invoiceId,
                'mail' => $e->mailId,
                'type' => mb_substr($e->type, 0, 60),
                'detail' => $e->detail,
                'actor' => $e->actor->userId,
                'name' => mb_substr($e->actor->name, 0, 200),
                'email' => mb_substr($e->actor->email, 0, 320),
                'at' => self::at($e->at),
                'extra' => $e->extra === [] ? null : json_encode($e->extra, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ],
        );
    }

    public function forSupplier(string $supplierId, ?DateTimeImmutable $from = null, ?DateTimeImmutable $until = null): array
    {
        $where = '[supplier_id] = :supplier';
        $params = ['supplier' => $supplierId];
        if ($from !== null) {
            $where .= ' AND [at] >= :from_at';
            $params['from_at'] = self::at($from);
        }
        if ($until !== null) {
            $where .= ' AND [at] < :until_at';
            $params['until_at'] = self::at($until);
        }
        $rows = $this->connection->fetchAll(
            'SELECT [id], [supplier_id], [invoice_id], [mail_id], [type], [detail], [actor_user_id], [actor_name], [actor_email], [at], [extra]
               FROM [finance].[ap2_history] WHERE ' . $where . ' ORDER BY [at] DESC, [id] DESC',
            $params,
        );

        return array_map(static function (array $row): Ap2HistoryEntry {
            $extra = is_string($row['extra'] ?? null) ? json_decode($row['extra'], true) : null;
            $clean = [];
            if (is_array($extra)) {
                foreach ($extra as $key => $value) {
                    $clean[(string) $key] = $value;
                }
            }

            return new Ap2HistoryEntry(
                self::id($row['id']),
                self::id($row['supplier_id']),
                self::text($row['type']),
                self::text($row['detail']),
                new Ap2Person(self::int($row['actor_user_id']), self::text($row['actor_name']), self::text($row['actor_email'])),
                self::when($row['at'], 'at'),
                self::textOrNull($row['invoice_id']),
                self::textOrNull($row['mail_id']),
                $clean,
            );
        }, $rows);
    }
}
