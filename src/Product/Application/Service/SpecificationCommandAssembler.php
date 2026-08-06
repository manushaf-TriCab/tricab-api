<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Application\Exception\InvalidSpecificationSet;
use TriCab\Core\Product\Domain\Entity\SpecificationDefinition;
use TriCab\Core\Product\Domain\Exception\InvalidSpecificationValueException;
use TriCab\Core\Product\Domain\Service\SpecificationValueValidator;
use TriCab\Core\Product\Domain\ValueObject\DisplayOrder;
use TriCab\Core\Product\Domain\ValueObject\SpecificationDataType;
use TriCab\Core\Product\Domain\ValueObject\SpecificationScope;

/**
 * @phpstan-import-type SpecDefinition from \TriCab\Core\Product\Contract\ProductSpecificationRepository
 * @phpstan-import-type SpecValue from \TriCab\Core\Product\Contract\ProductSpecificationRepository
 * @phpstan-import-type SpecValueInput from \TriCab\Core\Product\Application\Command\AssignProductSpecificationsCommand
 * @phpstan-import-type SpecPayload from SpecificationValueValidator
 */
final class SpecificationCommandAssembler
{
    /**
     * Validates each input value against its definition and returns the
     * SpecValue-shaped array expected by ProductSpecificationRepository.
     *
     * @param list<SpecDefinition> $definitions
     * @param list<SpecValueInput> $inputs
     * @return list<SpecValue>
     */
    public static function assemble(array $definitions, array $inputs, string $target): array
    {
        $byPublicId = [];
        foreach ($definitions as $definition) {
            $byPublicId[$definition['publicId']] = $definition;
        }

        $validator = new SpecificationValueValidator();
        $values = [];

        foreach ($inputs as $input) {
            $definitionPublicId = $input['definitionPublicId'];
            $definition = $byPublicId[$definitionPublicId] ?? null;
            if ($definition === null) {
                throw InvalidSpecificationSet::unknownDefinition($definitionPublicId);
            }

            $scope = SpecificationScope::fromString($definition['scopeCode']);
            if ($target === 'product' && !$scope->allowsProduct()) {
                throw InvalidSpecificationSet::scopeMismatch($definitionPublicId, 'product');
            }
            if ($target === 'variant' && !$scope->allowsVariant()) {
                throw InvalidSpecificationSet::scopeMismatch($definitionPublicId, 'variant');
            }

            $dataType = SpecificationDataType::fromString($definition['dataTypeCode']);

            if ($dataType->isSelect()) {
                $allowedOptionPublicIds = array_map(
                    static fn (array $option): string => $option['publicId'],
                    $definition['options'],
                );
                self::assertOptionSelection($dataType, $input, $allowedOptionPublicIds, $definitionPublicId);
            } else {
                $entity = new SpecificationDefinition(
                    id: null,
                    definitionCode: $definition['definitionCode'],
                    definitionName: $definition['definitionName'],
                    dataType: $dataType,
                    scope: $scope,
                    displayOrder: DisplayOrder::fromInt($definition['displayOrder']),
                    isRequired: $definition['isRequired'],
                    unit: $definition['unit'],
                );

                try {
                    $validator->assertValid($entity, self::toPayload($input), $target);
                } catch (InvalidSpecificationValueException $exception) {
                    throw InvalidSpecificationSet::requiredMissing($definitionPublicId);
                }
            }

            $values[] = self::toSpecValue($definitionPublicId, $input);
        }

        return $values;
    }

    /**
     * @param SpecValueInput $input
     * @return SpecPayload
     */
    private static function toPayload(array $input): array
    {
        return [
            'text' => $input['valueText'] ?? null,
            'longText' => $input['valueLongText'] ?? null,
            'integer' => $input['valueInteger'] ?? null,
            'decimal' => $input['valueDecimal'] ?? null,
            'boolean' => $input['valueBoolean'] ?? null,
            'date' => $input['valueDate'] ?? null,
            'url' => $input['valueUrl'] ?? null,
            'minDecimal' => $input['valueMinDecimal'] ?? null,
            'maxDecimal' => $input['valueMaxDecimal'] ?? null,
        ];
    }

    /**
     * @param SpecValueInput $input
     * @param list<string> $allowedOptionPublicIds
     */
    private static function assertOptionSelection(
        SpecificationDataType $dataType,
        array $input,
        array $allowedOptionPublicIds,
        string $definitionPublicId,
    ): void {
        if ($dataType->toString() === SpecificationDataType::SINGLE_SELECT) {
            $optionPublicId = $input['optionPublicId'] ?? null;
            if ($optionPublicId === null || !in_array($optionPublicId, $allowedOptionPublicIds, true)) {
                throw InvalidSpecificationSet::requiredMissing($definitionPublicId);
            }

            return;
        }

        $optionPublicIds = $input['optionPublicIds'] ?? [];
        if ($optionPublicIds === []) {
            throw InvalidSpecificationSet::requiredMissing($definitionPublicId);
        }
        foreach ($optionPublicIds as $optionPublicId) {
            if (!in_array($optionPublicId, $allowedOptionPublicIds, true)) {
                throw InvalidSpecificationSet::requiredMissing($definitionPublicId);
            }
        }
    }

    /**
     * @param SpecValueInput $input
     * @return SpecValue
     */
    private static function toSpecValue(string $definitionPublicId, array $input): array
    {
        return [
            'definitionPublicId' => $definitionPublicId,
            'valueText' => $input['valueText'] ?? null,
            'valueLongText' => $input['valueLongText'] ?? null,
            'valueInteger' => $input['valueInteger'] ?? null,
            'valueDecimal' => $input['valueDecimal'] ?? null,
            'valueBoolean' => $input['valueBoolean'] ?? null,
            'valueDate' => $input['valueDate'] ?? null,
            'valueUrl' => $input['valueUrl'] ?? null,
            'valueMinDecimal' => $input['valueMinDecimal'] ?? null,
            'valueMaxDecimal' => $input['valueMaxDecimal'] ?? null,
            'optionPublicId' => $input['optionPublicId'] ?? null,
            'optionPublicIds' => $input['optionPublicIds'] ?? [],
        ];
    }
}
