<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Canonical;

use TriCab\Core\Product\Migration\Support\MigrationPayload;

final class CanonicalSpecificationValue
{
    public function __construct(
        public readonly string $sourceField,
        public readonly ?string $definitionCode,
        public readonly string $rawValue,
        public readonly ?string $transformedValue,
        public readonly ?string $unitCode,
        public readonly string $scope,
        public readonly string $outcome,
        public readonly ?string $message = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sourceField' => $this->sourceField,
            'definitionCode' => $this->definitionCode,
            'rawValue' => $this->rawValue,
            'transformedValue' => $this->transformedValue,
            'unitCode' => $this->unitCode,
            'scope' => $this->scope,
            'outcome' => $this->outcome,
            'message' => $this->message,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            sourceField: MigrationPayload::string($data['sourceField'] ?? ''),
            definitionCode: MigrationPayload::stringOrNull($data['definitionCode'] ?? null),
            rawValue: MigrationPayload::string($data['rawValue'] ?? ''),
            transformedValue: MigrationPayload::stringOrNull($data['transformedValue'] ?? null),
            unitCode: MigrationPayload::stringOrNull($data['unitCode'] ?? null),
            scope: MigrationPayload::string($data['scope'] ?? 'variant', 'variant'),
            outcome: MigrationPayload::string($data['outcome'] ?? 'pending', 'pending'),
            message: MigrationPayload::stringOrNull($data['message'] ?? null),
        );
    }
}
