<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Contract\ProductAuditRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

/**
 * Records Product bounded-context audit events. Mirrors
 * Identity\UserManagementAuditRecorder. Masks sensitive values (e.g. pricing
 * unitPrice) before writing.
 */
final class ProductAuditRecorder
{
    public function __construct(
        private readonly ProductAuditRepository $audit,
    ) {
    }

    public function actorUserId(ActorContext $actor): UserId
    {
        return UserId::fromInt($actor->userId);
    }

    /**
     * @param array<string, mixed>|null $previousValues
     * @param array<string, mixed>|null $newValues
     */
    public function record(
        ActorContext $actor,
        ?ProductPublicId $productPublicId,
        string $entityTypeCode,
        ?int $entityId,
        string $actionCode,
        ?array $previousValues = null,
        ?array $newValues = null,
        ?string $sourceCode = 'admin',
    ): void {
        $this->audit->record(
            productPublicId: $productPublicId,
            entityTypeCode: $entityTypeCode,
            entityId: $entityId,
            actionCode: $actionCode,
            actorUserId: $this->actorUserId($actor),
            correlationId: $actor->correlationId,
            sourceCode: $sourceCode,
            previousValues: $this->mask($previousValues),
            newValues: $this->mask($newValues),
        );
    }

    /**
     * @param array<string, mixed>|null $values
     * @return array<string, mixed>|null
     */
    private function mask(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $masked = [];
        foreach ($values as $key => $value) {
            $lower = strtolower((string) $key);
            if (
                str_contains($lower, 'unitprice')
                || str_contains($lower, 'price')
                || str_contains($lower, 'basecost')
            ) {
                $masked[$key] = '***';
                continue;
            }
            $masked[$key] = $value;
        }

        return $masked;
    }
}
