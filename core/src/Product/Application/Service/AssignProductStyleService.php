<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\AssignProductStyleCommand;
use TriCab\Core\Product\Application\DTO\ProductStyleAssignment;
use TriCab\Core\Product\Application\Exception\ProductNotFound;
use TriCab\Core\Product\Application\Exception\ProductStyleNotFound;
use TriCab\Core\Product\Contract\ProductAdminQueryRepository;
use TriCab\Core\Product\Contract\ProductStyleRepository;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class AssignProductStyleService
{
    public function __construct(
        private readonly ProductStyleRepository $styles,
        private readonly ProductAdminQueryRepository $products,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function getAssignment(string $productPublicId): ?ProductStyleAssignment
    {
        ProductPublicId::fromString($productPublicId);

        return $this->styles->findAssignmentByProductPublicId($productPublicId);
    }

    public function execute(ActorContext $actor, AssignProductStyleCommand $command): ?ProductStyleAssignment
    {
        return $this->transaction->run(function () use ($actor, $command): ?ProductStyleAssignment {
            $productPublicId = ProductPublicId::fromString($command->productPublicId);
            if ($this->products->findDetailByPublicId($productPublicId) === null) {
                throw ProductNotFound::forPublicId($command->productPublicId);
            }
            $previous = $this->styles->findAssignmentByProductPublicId($productPublicId->toString());
            $stylePublicId = $command->stylePublicId !== null && trim($command->stylePublicId) !== ''
                ? trim($command->stylePublicId)
                : null;

            if ($stylePublicId === null) {
                $this->styles->unassignProduct($productPublicId->toString(), $this->audit->actorUserId($actor));
                $this->audit->record(
                    actor: $actor,
                    productPublicId: $productPublicId,
                    entityTypeCode: 'product-style-assignment',
                    entityId: null,
                    actionCode: 'unassigned',
                    previousValues: ['stylePublicId' => $previous?->stylePublicId],
                    newValues: ['stylePublicId' => null],
                );

                return null;
            }

            if ($this->styles->findByPublicId($stylePublicId, false) === null) {
                throw ProductStyleNotFound::forPublicId($stylePublicId);
            }
            $assignment = $this->styles->assignProduct(
                $productPublicId->toString(),
                $stylePublicId,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: $productPublicId,
                entityTypeCode: 'product-style-assignment',
                entityId: null,
                actionCode: 'assigned',
                previousValues: ['stylePublicId' => $previous?->stylePublicId],
                newValues: ['stylePublicId' => $assignment->stylePublicId, 'styleCode' => $assignment->styleCode],
            );

            return $assignment;
        });
    }

    /**
     * Persist only an explicit operator-confirmed assignment list.
     * Never means "apply all EXACT_MATCH". Empty list writes nothing.
     *
     * @param list<array{productPublicId?: string, productStylePublicId?: string}> $assignments
     * @return list<array{productPublicId: string, productStylePublicId: string, applied: bool, reason: string}>
     */
    public function applySelected(ActorContext $actor, array $assignments, bool $confirmed = false): array
    {
        if (!$confirmed) {
            throw InvalidProductValue::for('confirmed', 'false');
        }

        return $this->transaction->run(function () use ($actor, $assignments): array {
            $results = [];
            foreach ($assignments as $row) {
                $productPublicId = trim((string) ($row['productPublicId'] ?? ''));
                $stylePublicId = trim((string) ($row['productStylePublicId'] ?? ''));
                if ($productPublicId === '' || $stylePublicId === '') {
                    $results[] = [
                        'productPublicId' => $productPublicId,
                        'productStylePublicId' => $stylePublicId,
                        'applied' => false,
                        'reason' => 'Both productPublicId and productStylePublicId are required.',
                    ];
                    continue;
                }
                try {
                    ProductPublicId::fromString($productPublicId);
                } catch (\Throwable $exception) {
                    $results[] = [
                        'productPublicId' => $productPublicId,
                        'productStylePublicId' => $stylePublicId,
                        'applied' => false,
                        'reason' => $exception->getMessage(),
                    ];
                    continue;
                }
                if ($this->products->findDetailByPublicId(ProductPublicId::fromString($productPublicId)) === null) {
                    $results[] = [
                        'productPublicId' => $productPublicId,
                        'productStylePublicId' => $stylePublicId,
                        'applied' => false,
                        'reason' => 'Product was not found.',
                    ];
                    continue;
                }
                $style = $this->styles->findByPublicId($stylePublicId, false);
                if ($style === null) {
                    $results[] = [
                        'productPublicId' => $productPublicId,
                        'productStylePublicId' => $stylePublicId,
                        'applied' => false,
                        'reason' => 'ProductStyle was not found.',
                    ];
                    continue;
                }
                if (!$style->isActive || $style->isDeleted) {
                    $results[] = [
                        'productPublicId' => $productPublicId,
                        'productStylePublicId' => $stylePublicId,
                        'applied' => false,
                        'reason' => 'ProductStyle is inactive or deleted.',
                    ];
                    continue;
                }
                $previous = $this->styles->findAssignmentByProductPublicId($productPublicId);
                if ($previous !== null && $previous->stylePublicId === $style->publicId) {
                    $results[] = [
                        'productPublicId' => $productPublicId,
                        'productStylePublicId' => $stylePublicId,
                        'applied' => false,
                        'reason' => 'ALREADY_ASSIGNED_SAME: no write.',
                    ];
                    continue;
                }
                if ($previous !== null && $previous->stylePublicId !== $style->publicId) {
                    $results[] = [
                        'productPublicId' => $productPublicId,
                        'productStylePublicId' => $stylePublicId,
                        'applied' => false,
                        'reason' => 'ALREADY_ASSIGNED_CONFLICT: existing assignment is ' . $previous->styleCode . '. Re-assignment requires separate approval.',
                    ];
                    continue;
                }
                $assignment = $this->styles->assignProduct(
                    $productPublicId,
                    $style->publicId,
                    $this->audit->actorUserId($actor),
                );
                $this->audit->record(
                    actor: $actor,
                    productPublicId: ProductPublicId::fromString($productPublicId),
                    entityTypeCode: 'product-style-assignment',
                    entityId: null,
                    actionCode: 'assigned',
                    previousValues: ['stylePublicId' => $previous?->stylePublicId],
                    newValues: ['stylePublicId' => $assignment->stylePublicId, 'styleCode' => $assignment->styleCode],
                );
                $results[] = [
                    'productPublicId' => $productPublicId,
                    'productStylePublicId' => $stylePublicId,
                    'applied' => true,
                    'reason' => 'Assignment stored. Shared data and inheritance were not changed.',
                ];
            }

            return $results;
        });
    }

    /**
     * Replace an existing assignment only when the current style matches fromStylePublicId/fromStyleCode.
     *
     * @param list<array{productPublicId?: string, fromStyleCode?: string, toStylePublicId?: string, toStyleCode?: string}> $replacements
     * @return list<array{productPublicId: string, applied: bool, reason: string}>
     */
    public function replaceSelected(ActorContext $actor, array $replacements, bool $confirmed = false): array
    {
        if (!$confirmed) {
            throw InvalidProductValue::for('confirmed', 'false');
        }

        return $this->transaction->run(function () use ($actor, $replacements): array {
            $results = [];
            foreach ($replacements as $row) {
                $productPublicId = trim((string) ($row['productPublicId'] ?? ''));
                $fromCode = strtoupper(trim((string) ($row['fromStyleCode'] ?? '')));
                $toPublicId = trim((string) ($row['toStylePublicId'] ?? ''));
                $toCode = strtoupper(trim((string) ($row['toStyleCode'] ?? '')));
                if ($productPublicId === '' || $fromCode === '' || ($toPublicId === '' && $toCode === '')) {
                    $results[] = [
                        'productPublicId' => $productPublicId,
                        'applied' => false,
                        'reason' => 'productPublicId, fromStyleCode, and toStyle identity are required.',
                    ];
                    continue;
                }
                if ($this->products->findDetailByPublicId(ProductPublicId::fromString($productPublicId)) === null) {
                    $results[] = [
                        'productPublicId' => $productPublicId,
                        'applied' => false,
                        'reason' => 'Product was not found.',
                    ];
                    continue;
                }
                $previous = $this->styles->findAssignmentByProductPublicId($productPublicId);
                if ($previous === null || strtoupper($previous->styleCode) !== $fromCode) {
                    $results[] = [
                        'productPublicId' => $productPublicId,
                        'applied' => false,
                        'reason' => 'Current assignment does not match fromStyleCode ' . $fromCode . '.',
                    ];
                    continue;
                }
                $style = $toPublicId !== ''
                    ? $this->styles->findByPublicId($toPublicId, false)
                    : $this->styles->findByStyleCode($toCode, false);
                if ($style === null || !$style->isActive || $style->isDeleted) {
                    $results[] = [
                        'productPublicId' => $productPublicId,
                        'applied' => false,
                        'reason' => 'Target ProductStyle was not found or is inactive.',
                    ];
                    continue;
                }
                $assignment = $this->styles->assignProduct(
                    $productPublicId,
                    $style->publicId,
                    $this->audit->actorUserId($actor),
                );
                $this->audit->record(
                    actor: $actor,
                    productPublicId: ProductPublicId::fromString($productPublicId),
                    entityTypeCode: 'product-style-assignment',
                    entityId: null,
                    actionCode: 'replaced',
                    previousValues: ['stylePublicId' => $previous->stylePublicId, 'styleCode' => $previous->styleCode],
                    newValues: ['stylePublicId' => $assignment->stylePublicId, 'styleCode' => $assignment->styleCode],
                );
                $results[] = [
                    'productPublicId' => $productPublicId,
                    'applied' => true,
                    'reason' => 'Assignment replaced from ' . $previous->styleCode . ' to ' . $assignment->styleCode . '.',
                ];
            }

            return $results;
        });
    }
}
