<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\AddProductDocumentCommand;
use TriCab\Core\Product\Application\DTO\ProductDocumentSummary;
use TriCab\Core\Product\Contract\ProductDocumentRepository;
use TriCab\Core\Product\Domain\ValueObject\DocumentAccessCode;
use TriCab\Core\Product\Domain\ValueObject\MediaStoragePath;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class AddProductDocumentService
{
    public function __construct(
        private readonly ProductDocumentRepository $documents,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, AddProductDocumentCommand $command): ProductDocumentSummary
    {
        return $this->transaction->run(function () use ($actor, $command): ProductDocumentSummary {
            $productPublicId = ProductPublicId::fromString($command->productPublicId);
            $accessCode = DocumentAccessCode::fromString($command->accessCode);
            $storagePath = MediaStoragePath::fromString($command->storagePath);
            $actorUserId = $this->audit->actorUserId($actor);

            $created = $this->documents->create(
                $productPublicId,
                [
                    'documentTypeCode' => strtolower(trim($command->documentTypeCode)),
                    'title' => $command->title,
                    'storagePath' => $storagePath->toString(),
                    'accessCode' => $accessCode->toString(),
                    'versionLabel' => $command->versionLabel,
                    'languageCode' => $command->languageCode,
                    'regionPublicId' => $command->regionPublicId,
                    'marketPublicId' => $command->marketPublicId,
                    'effectiveFromUtc' => $command->effectiveFromUtc,
                    'effectiveToUtc' => $command->effectiveToUtc,
                    'displayOrder' => $command->displayOrder,
                    'publicUrl' => $command->publicUrl,
                ],
                $actorUserId,
            );

            $this->audit->record(
                actor: $actor,
                productPublicId: $productPublicId,
                entityTypeCode: 'product-document',
                entityId: null,
                actionCode: 'created',
                newValues: ['title' => $created->title, 'documentTypeCode' => $created->documentTypeCode],
            );

            return $created;
        });
    }
}
