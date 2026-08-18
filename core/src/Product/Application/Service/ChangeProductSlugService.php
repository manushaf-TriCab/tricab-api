<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\ChangeProductSlugCommand;
use TriCab\Core\Product\Contract\ProductSeoRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductSlug;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

/**
 * When the primary (non-regional) slug changes, records a 301 redirect from
 * the previous primary slug. Regional-slug redirect history is not tracked
 * yet — ProductSeoRepository::findPrimarySlug only resolves the default
 * (no-region) slug.
 */
final class ChangeProductSlugService
{
    private const REDIRECT_STATUS_MOVED_PERMANENTLY = 301;

    public function __construct(
        private readonly ProductSeoRepository $seo,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, ChangeProductSlugCommand $command): void
    {
        $this->transaction->run(function () use ($actor, $command): void {
            $productPublicId = ProductPublicId::fromString($command->productPublicId);
            $newSlug = ProductSlug::fromString($command->newSlug);
            $actorUserId = $this->audit->actorUserId($actor);

            $previousPrimarySlug = $command->regionPublicId === null
                ? $this->seo->findPrimarySlug($productPublicId)
                : null;

            $this->seo->upsertSlug(
                $productPublicId,
                $newSlug,
                $command->isPrimary,
                $command->regionPublicId,
                $actorUserId,
            );

            if (
                $command->isPrimary
                && $command->regionPublicId === null
                && $previousPrimarySlug !== null
                && !$previousPrimarySlug->equals($newSlug)
            ) {
                $this->seo->createRedirect(
                    $productPublicId,
                    $previousPrimarySlug->toString(),
                    $newSlug->toString(),
                    null,
                    self::REDIRECT_STATUS_MOVED_PERMANENTLY,
                    $actorUserId,
                );
            }

            $this->audit->record(
                actor: $actor,
                productPublicId: $productPublicId,
                entityTypeCode: 'product-seo',
                entityId: null,
                actionCode: 'slug-changed',
                previousValues: ['slug' => $previousPrimarySlug?->toString()],
                newValues: ['slug' => $newSlug->toString()],
            );
        });
    }
}
