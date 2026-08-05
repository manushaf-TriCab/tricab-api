<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Application\Command\PreviewProductCodeCommand;
use TriCab\Core\Product\Application\DTO\ProductCodePreviewResult;
use TriCab\Core\Product\Contract\ProductCodeRuleRepository;
use TriCab\Core\Product\Contract\ProductRepository;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\ValueObject\ProductCodeValue;

/**
 * Read-only preview: never persists a code.
 */
final class PreviewProductCodeService
{
    public function __construct(
        private readonly ProductCodeRuleRepository $codeRules,
        private readonly ProductRepository $products,
    ) {
    }

    public function execute(PreviewProductCodeCommand $command): ProductCodePreviewResult
    {
        $preview = $this->codeRules->preview($command->ruleSetPublicId, $command->segmentValues);

        $validationErrors = [];
        $warnings = [];
        $isUnique = false;

        try {
            $code = ProductCodeValue::fromString($preview['code']);
            $isUnique = !$this->products->productCodeExists($code);
            if (!$isUnique) {
                $warnings[] = sprintf('Product code "%s" is already assigned to another product.', $code->toString());
            }
        } catch (InvalidProductValue $exception) {
            $validationErrors[] = $exception->getMessage();
        }

        return new ProductCodePreviewResult(
            generatedCode: $preview['code'],
            explanation: $preview['explanation'],
            validationErrors: $validationErrors,
            warnings: $warnings,
            isUnique: $isUnique,
        );
    }
}
