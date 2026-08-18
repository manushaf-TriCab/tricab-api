<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use DateTimeImmutable;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\AssignProductCodeCommand;
use TriCab\Core\Product\Application\DTO\ProductMutationResult;
use TriCab\Core\Product\Application\Exception\DuplicateProductCode;
use TriCab\Core\Product\Application\Exception\ProductNotFound;
use TriCab\Core\Product\Contract\ProductCodeRuleRepository;
use TriCab\Core\Product\Contract\ProductRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductCodeValue;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

/**
 * Regenerates the code from the rule set + segment values, validates
 * uniqueness, and assigns it as the product's primary code.
 */
final class AssignProductCodeService
{
    public function __construct(
        private readonly ProductCodeRuleRepository $codeRules,
        private readonly ProductRepository $products,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, AssignProductCodeCommand $command): ProductMutationResult
    {
        return $this->transaction->run(function () use ($actor, $command): ProductMutationResult {
            $productPublicId = ProductPublicId::fromString($command->productPublicId);
            $product = $this->products->findByPublicId($productPublicId);
            if ($product === null) {
                throw ProductNotFound::forPublicId($command->productPublicId);
            }

            $preview = $this->codeRules->preview($command->ruleSetPublicId, $command->segmentValues);
            $code = ProductCodeValue::fromString($preview['code']);

            if ($this->products->productCodeExists($code, $productPublicId)) {
                throw DuplicateProductCode::forCode($code->toString());
            }

            $actorUserId = $this->audit->actorUserId($actor);
            $this->codeRules->assignPrimaryCode(
                productPublicId: $productPublicId,
                code: $code,
                explanation: $preview['explanation'],
                ruleSetPublicId: $command->ruleSetPublicId,
                actorUserId: $actorUserId,
            );

            $this->audit->record(
                actor: $actor,
                productPublicId: $productPublicId,
                entityTypeCode: 'product',
                entityId: $product->id()?->toInt(),
                actionCode: 'code-assigned',
                newValues: ['productCode' => $code->toString()],
            );

            return new ProductMutationResult(
                publicId: $productPublicId->toString(),
                rowVersion: $product->rowVersion()?->toBase64() ?? '',
                statusCode: $product->status()->toString(),
                createdAtUtc: null,
                updatedAtUtc: (new DateTimeImmutable())->format(DATE_ATOM),
            );
        });
    }
}
