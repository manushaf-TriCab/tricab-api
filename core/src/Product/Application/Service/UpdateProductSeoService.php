<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\UpdateProductSeoCommand;
use TriCab\Core\Product\Contract\ProductSeoRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class UpdateProductSeoService
{
    public function __construct(
        private readonly ProductSeoRepository $seo,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, UpdateProductSeoCommand $command): void
    {
        $this->transaction->run(function () use ($actor, $command): void {
            $productPublicId = ProductPublicId::fromString($command->productPublicId);
            $actorUserId = $this->audit->actorUserId($actor);

            $this->seo->upsertSeo(
                $productPublicId,
                [
                    'pageTitle' => $command->pageTitle,
                    'metaDescription' => $command->metaDescription,
                    'canonicalUrl' => $command->canonicalUrl,
                    'openGraphTitle' => $command->openGraphTitle,
                    'openGraphDescription' => $command->openGraphDescription,
                    'openGraphImageUrl' => $command->openGraphImageUrl,
                    'searchKeywords' => $command->searchKeywords,
                    'regionPublicId' => $command->regionPublicId,
                ],
                $actorUserId,
            );

            $this->audit->record(
                actor: $actor,
                productPublicId: $productPublicId,
                entityTypeCode: 'product-seo',
                entityId: null,
                actionCode: 'updated',
                newValues: ['pageTitle' => $command->pageTitle, 'regionPublicId' => $command->regionPublicId],
            );
        });
    }
}
