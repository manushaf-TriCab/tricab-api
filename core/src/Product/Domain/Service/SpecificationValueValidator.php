<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\Service;

use TriCab\Core\Product\Domain\Entity\SpecificationDefinition;
use TriCab\Core\Product\Domain\Exception\InvalidSpecificationValueException;
use TriCab\Core\Product\Domain\ValueObject\SpecificationDataType;
use TriCab\Core\Product\Domain\ValueObject\SpecificationOptionId;
use TriCab\Core\Product\Domain\ValueObject\SpecificationScope;

/**
 * Validates a typed specification payload against a definition.
 *
 * @phpstan-type SpecPayload array{
 *   text?: ?string,
 *   longText?: ?string,
 *   integer?: ?int,
 *   decimal?: ?string,
 *   boolean?: ?bool,
 *   date?: ?string,
 *   url?: ?string,
 *   minDecimal?: ?string,
 *   maxDecimal?: ?string,
 *   optionId?: ?int,
 *   optionIds?: list<int>
 * }
 */
final class SpecificationValueValidator
{
    /**
     * @param SpecPayload $payload
     * @param list<int> $allowedOptionIds
     */
    public function assertValid(
        SpecificationDefinition $definition,
        array $payload,
        string $assignmentTarget,
        array $allowedOptionIds = [],
    ): void {
        $scope = $definition->scope();
        if ($assignmentTarget === 'product' && !$scope->allowsProduct()) {
            throw InvalidSpecificationValueException::for(
                'Specification is not assignable at product level.',
            );
        }
        if ($assignmentTarget === 'variant' && !$scope->allowsVariant()) {
            throw InvalidSpecificationValueException::for(
                'Specification is not assignable at variant level.',
            );
        }

        $type = $definition->dataType()->toString();

        match ($type) {
            SpecificationDataType::TEXT => $this->assertNonEmptyString($payload['text'] ?? null, 'text'),
            SpecificationDataType::LONG_TEXT => $this->assertNonEmptyString($payload['longText'] ?? null, 'longText'),
            SpecificationDataType::INTEGER => $this->assertInteger($definition, $payload['integer'] ?? null),
            SpecificationDataType::DECIMAL,
            SpecificationDataType::MEASUREMENT => $this->assertDecimal($definition, $payload['decimal'] ?? null),
            SpecificationDataType::BOOLEAN => $this->assertBoolean($payload['boolean'] ?? null),
            SpecificationDataType::DATE => $this->assertNonEmptyString($payload['date'] ?? null, 'date'),
            SpecificationDataType::URL => $this->assertUrl($payload['url'] ?? null),
            SpecificationDataType::RANGE => $this->assertRange($definition, $payload),
            SpecificationDataType::SINGLE_SELECT => $this->assertSingleOption($payload['optionId'] ?? null, $allowedOptionIds),
            SpecificationDataType::MULTI_SELECT => $this->assertMultiOptions($payload['optionIds'] ?? [], $allowedOptionIds),
            default => throw InvalidSpecificationValueException::for('Unsupported specification data type.'),
        };
    }

    private function assertNonEmptyString(?string $value, string $field): void
    {
        if ($value === null || trim($value) === '') {
            throw InvalidSpecificationValueException::for(sprintf('Specification %s value is required.', $field));
        }
    }

    private function assertBoolean(mixed $value): void
    {
        if (!is_bool($value)) {
            throw InvalidSpecificationValueException::for('Specification boolean value is required.');
        }
    }

    private function assertInteger(SpecificationDefinition $definition, mixed $value): void
    {
        if (!is_int($value)) {
            throw InvalidSpecificationValueException::for('Specification integer value is required.');
        }
        $this->assertWithinMinMax($definition, (float) $value);
    }

    private function assertDecimal(SpecificationDefinition $definition, mixed $value): void
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw InvalidSpecificationValueException::for('Specification decimal value is required.');
        }
        $asString = is_string($value) ? trim($value) : (string) $value;
        if ($asString === '' || !is_numeric($asString)) {
            throw InvalidSpecificationValueException::for('Specification decimal value is invalid.');
        }
        $precision = $definition->decimalPrecision();
        if ($precision !== null && preg_match('/\\.(\\d+)$/', $asString, $m) === 1 && strlen($m[1]) > $precision) {
            throw InvalidSpecificationValueException::for(
                sprintf('Specification decimal exceeds precision %d.', $precision),
            );
        }
        $this->assertWithinMinMax($definition, (float) $asString);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertRange(SpecificationDefinition $definition, array $payload): void
    {
        $min = $payload['minDecimal'] ?? null;
        $max = $payload['maxDecimal'] ?? null;
        if (!is_string($min) || !is_string($max) || !is_numeric($min) || !is_numeric($max)) {
            throw InvalidSpecificationValueException::for('Specification range requires min and max decimals.');
        }
        if ((float) $min > (float) $max) {
            throw InvalidSpecificationValueException::for('Specification range minimum cannot exceed maximum.');
        }
        $this->assertWithinMinMax($definition, (float) $min);
        $this->assertWithinMinMax($definition, (float) $max);
    }

    /**
     * @param list<int> $allowedOptionIds
     */
    private function assertSingleOption(mixed $optionId, array $allowedOptionIds): void
    {
        if (!is_int($optionId) || $optionId < 1) {
            throw InvalidSpecificationValueException::for('Specification option is required.');
        }
        if ($allowedOptionIds !== [] && !in_array($optionId, $allowedOptionIds, true)) {
            throw InvalidSpecificationValueException::for('Specification option is not valid for this definition.');
        }
        SpecificationOptionId::fromInt($optionId);
    }

    /**
     * @param list<int> $optionIds
     * @param list<int> $allowedOptionIds
     */
    private function assertMultiOptions(array $optionIds, array $allowedOptionIds): void
    {
        if ($optionIds === []) {
            throw InvalidSpecificationValueException::for('At least one specification option is required.');
        }
        foreach ($optionIds as $optionId) {
            $this->assertSingleOption($optionId, $allowedOptionIds);
        }
    }

    private function assertUrl(?string $url): void
    {
        if ($url === null || trim($url) === '') {
            throw InvalidSpecificationValueException::for('Specification URL value is required.');
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw InvalidSpecificationValueException::for('Specification URL value is invalid.');
        }
    }

    private function assertWithinMinMax(SpecificationDefinition $definition, float $value): void
    {
        $min = $definition->minimumValue();
        $max = $definition->maximumValue();
        if ($min !== null && $value < (float) $min) {
            throw InvalidSpecificationValueException::for('Specification value is below minimum.');
        }
        if ($max !== null && $value > (float) $max) {
            throw InvalidSpecificationValueException::for('Specification value is above maximum.');
        }
    }
}
