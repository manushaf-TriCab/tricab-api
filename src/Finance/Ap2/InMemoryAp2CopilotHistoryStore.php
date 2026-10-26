<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Core\Finance\Ap2\Contract\Ap2CopilotHistoryStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2CopilotMessage;

/** The Copilot conversations held in memory. */
final class InMemoryAp2CopilotHistoryStore implements Ap2CopilotHistoryStore
{
    /** @var list<Ap2CopilotMessage> in the order they were stored */
    private array $rows = [];

    public function append(int $userId, ?string $mailId, ?string $supplierId, string $role, string $message, ?array $proposals = null): void
    {
        if ($mailId === null && $supplierId === null) {
            return;
        }
        $this->rows[] = new Ap2CopilotMessage(
            bin2hex(random_bytes(16)),
            $userId,
            $mailId,
            $supplierId,
            $role === 'assistant' ? 'assistant' : 'user',
            $message,
            $proposals ?? [],
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
    }

    public function recent(int $userId, ?string $mailId, ?string $supplierId, int $limit): array
    {
        $mine = $this->conversation($userId, $mailId, $supplierId);

        return $limit <= 0 ? [] : array_slice($mine, -$limit);
    }

    public function prune(int $userId, ?string $mailId, ?string $supplierId, int $keep): void
    {
        $mine = $this->conversation($userId, $mailId, $supplierId);
        $drop = array_slice($mine, 0, max(0, count($mine) - max(0, $keep)));
        $ids = array_map(static fn (Ap2CopilotMessage $m): string => $m->id, $drop);
        $this->rows = array_values(array_filter($this->rows, static fn (Ap2CopilotMessage $m): bool => !in_array($m->id, $ids, true)));
    }

    /** @return list<Ap2CopilotMessage> */
    private function conversation(int $userId, ?string $mailId, ?string $supplierId): array
    {
        if ($mailId === null && $supplierId === null) {
            return [];
        }

        return array_values(array_filter($this->rows, static fn (Ap2CopilotMessage $m): bool => $m->userId === $userId
            && ($mailId !== null ? $m->mailId === $mailId : ($m->mailId === null && $m->supplierId === $supplierId))));
    }
}
