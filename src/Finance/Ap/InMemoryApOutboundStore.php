<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap;

use TriCab\Core\Finance\Ap\Contract\ApOutboundStore;
use TriCab\Core\Finance\Ap\Domain\ApOutboundMessage;
use TriCab\Core\Finance\Ap\Domain\ApOutboundTarget;

/** Tri-AP's outbound mail links held in memory: the tests', and a database-less run's. Same order as the SQL store. */
final class InMemoryApOutboundStore implements ApOutboundStore
{
    /** @var array<string, ApOutboundMessage> */
    private array $messages = [];

    public function transactional(callable $work): mixed
    {
        return $work();
    }

    public function add(ApOutboundMessage $message): void
    {
        $this->messages[$message->id] = $message;
    }

    public function of(ApOutboundTarget $target, int $limit): array
    {
        $rows = array_values(array_filter(
            $this->messages,
            static fn (ApOutboundMessage $row): bool => $row->target->kind === $target->kind && $row->target->id === $target->id,
        ));
        usort($rows, static fn (ApOutboundMessage $a, ApOutboundMessage $b): int => [$b->createdAt, $a->id] <=> [$a->createdAt, $b->id]);

        return array_slice($rows, 0, max(0, $limit));
    }

    public function findByDelivery(string $deliveryId): ?ApOutboundMessage
    {
        foreach ($this->messages as $row) {
            if ($row->deliveryId === $deliveryId) {
                return $row;
            }
        }

        return null;
    }
}
