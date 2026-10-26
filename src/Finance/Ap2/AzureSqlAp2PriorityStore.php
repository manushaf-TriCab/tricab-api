<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2;

use TriCab\Core\Finance\Ap2\Contract\Ap2PriorityStore;

/** `finance.ap2_priorities`. */
final class AzureSqlAp2PriorityStore extends AzureSqlAp2Support implements Ap2PriorityStore
{
    public function forUser(int $userId): array
    {
        $out = [];
        foreach ($this->connection->fetchAll('SELECT [kind], [item_id], [flag], [pin] FROM [finance].[ap2_priorities] WHERE [user_id] = :user', ['user' => $userId]) as $row) {
            $out[self::text($row['kind']) . ':' . self::text($row['item_id'])] = ['flag' => self::bool($row['flag']), 'pin' => self::bool($row['pin'])];
        }

        return $out;
    }

    public function put(int $userId, string $kind, string $itemId, bool $flag, bool $pin): void
    {
        $this->transactional(function () use ($userId, $kind, $itemId, $flag, $pin): null {
            $this->connection->execute(
                'DELETE FROM [finance].[ap2_priorities] WHERE [user_id] = :user AND [kind] = :kind AND [item_id] = :item',
                ['user' => $userId, 'kind' => $kind, 'item' => $itemId],
            );
            if ($flag || $pin) {
                $this->connection->execute(
                    'INSERT INTO [finance].[ap2_priorities] ([user_id], [kind], [item_id], [flag], [pin]) VALUES (:user, :kind, :item, :flag, :pin)',
                    ['user' => $userId, 'kind' => $kind, 'item' => $itemId, 'flag' => $flag ? 1 : 0, 'pin' => $pin ? 1 : 0],
                );
            }

            return null;
        });
    }
}
