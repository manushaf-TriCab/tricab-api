<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Core\Finance\Ap2\Contract\Ap2CopilotHistoryStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2CopilotMessage;

/** `finance.ap2_copilot_messages`. */
final class AzureSqlAp2CopilotHistoryStore extends AzureSqlAp2Support implements Ap2CopilotHistoryStore
{
    public function append(int $userId, ?string $mailId, ?string $supplierId, string $role, string $message, ?array $proposals = null): void
    {
        if ($mailId === null && $supplierId === null) {
            return;
        }
        $this->connection->execute(
            'INSERT INTO [finance].[ap2_copilot_messages] ([id], [user_id], [mail_id], [supplier_id], [role], [message], [proposals], [created_at])
             VALUES (:id, :user, :mail, :supplier, :role, :message, :proposals, :at)',
            [
                'id' => bin2hex(random_bytes(16)),
                'user' => $userId,
                'mail' => $mailId,
                'supplier' => $supplierId,
                'role' => $role === 'assistant' ? 'assistant' : 'user',
                'message' => $message,
                'proposals' => $proposals === null || $proposals === [] ? null : json_encode($proposals, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'at' => self::at(new DateTimeImmutable('now', new DateTimeZone('UTC'))),
            ],
        );
    }

    public function recent(int $userId, ?string $mailId, ?string $supplierId, int $limit): array
    {
        [$where, $params] = self::conversation($userId, $mailId, $supplierId);
        if ($where === null) {
            return [];
        }
        $rows = $this->connection->fetchAll(
            'SELECT TOP (' . max(1, min(500, $limit)) . ') [id], [user_id], [mail_id], [supplier_id], [role], [message], [proposals], [created_at]
               FROM [finance].[ap2_copilot_messages] WHERE ' . $where . ' ORDER BY [created_at] DESC, [id] DESC',
            $params,
        );

        return array_reverse(array_map(static function (array $row): Ap2CopilotMessage {
            $proposals = is_string($row['proposals'] ?? null) ? json_decode($row['proposals'], true) : null;
            $clean = [];
            if (is_array($proposals)) {
                foreach ($proposals as $proposal) {
                    if (is_array($proposal)) {
                        $clean[] = array_combine(array_map('strval', array_keys($proposal)), array_values($proposal));
                    }
                }
            }

            return new Ap2CopilotMessage(
                self::id($row['id']),
                self::int($row['user_id']),
                self::textOrNull($row['mail_id'] ?? null),
                self::textOrNull($row['supplier_id'] ?? null),
                self::text($row['role']) === 'assistant' ? 'assistant' : 'user',
                self::text($row['message']),
                $clean,
                self::when($row['created_at'], 'created_at'),
            );
        }, $rows));
    }

    public function prune(int $userId, ?string $mailId, ?string $supplierId, int $keep): void
    {
        [$where, $params] = self::conversation($userId, $mailId, $supplierId);
        if ($where === null) {
            return;
        }
        $this->connection->execute(
            'DELETE FROM [finance].[ap2_copilot_messages] WHERE ' . $where . ' AND [id] NOT IN (
                SELECT TOP (' . max(0, min(500, $keep)) . ') [id] FROM [finance].[ap2_copilot_messages] WHERE ' . $where . ' ORDER BY [created_at] DESC, [id] DESC)',
            $params,
        );
    }

    /**
     * @return array{0: string|null, 1: array<string, int|string>} the WHERE of one conversation (null when there is none)
     */
    private static function conversation(int $userId, ?string $mailId, ?string $supplierId): array
    {
        if ($mailId !== null) {
            return ['[user_id] = :user AND [mail_id] = :mail', ['user' => $userId, 'mail' => $mailId]];
        }
        if ($supplierId !== null) {
            return ['[user_id] = :user AND [mail_id] IS NULL AND [supplier_id] = :supplier', ['user' => $userId, 'supplier' => $supplierId]];
        }

        return [null, []];
    }
}
