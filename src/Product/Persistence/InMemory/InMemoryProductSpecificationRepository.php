<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence\InMemory;

use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\SpecificationDefinitionRecord;
use TriCab\Core\Product\Application\DTO\SpecificationGroupRecord;
use TriCab\Core\Product\Application\DTO\SpecificationOptionRecord;
use TriCab\Core\Product\Application\DTO\SpecificationUnitRecord;
use TriCab\Core\Product\Application\DTO\SpecificationUsage;
use TriCab\Core\Product\Application\DTO\SpecificationValidationRules;
use TriCab\Core\Product\Application\Exception\SpecificationMasterConcurrencyConflict;
use TriCab\Core\Product\Application\Exception\SpecificationMasterNotFound;
use TriCab\Core\Product\Contract\ProductSpecificationRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantPublicId;

/**
 * In-memory ProductSpecificationRepository including Phase 11 master-data admin.
 */
final class InMemoryProductSpecificationRepository implements ProductSpecificationRepository
{
    /** @var list<array<string, mixed>> */
    private array $groups = [];
    /** @var list<array<string, mixed>> */
    private array $units = [];
    /** @var list<array<string, mixed>> */
    private array $definitions = [];
    /** @var list<array<string, mixed>> */
    private array $options = [];
    /** @var array<string, list<array<string, mixed>>> */
    private array $productValues = [];
    /** @var array<string, list<array<string, mixed>>> */
    private array $variantValues = [];
    private int $seq = 1;
    private int $rowVersion = 1;

    public function listDefinitions(bool $activeOnly = true): array
    {
        $items = [];
        foreach ($this->definitions as $row) {
            if (($row['deletedAtUtc'] ?? null) !== null) {
                continue;
            }
            if ($activeOnly && !($row['isActive'] ?? true)) {
                continue;
            }
            $items[] = $this->toLegacyDefinition($row);
        }

        return $items;
    }

    public function listProductValues(ProductPublicId $productPublicId): array
    {
        return $this->productValues[$productPublicId->toString()] ?? [];
    }

    public function listVariantValues(ProductVariantPublicId $variantPublicId): array
    {
        return $this->variantValues[$variantPublicId->toString()] ?? [];
    }

    public function replaceProductValues(ProductPublicId $productPublicId, array $values, UserId $actorUserId): void
    {
        $this->productValues[$productPublicId->toString()] = $values;
    }

    public function replaceVariantValues(ProductVariantPublicId $variantPublicId, array $values, UserId $actorUserId): void
    {
        $this->variantValues[$variantPublicId->toString()] = $values;
    }

    public function listGroupsAdmin(array $filters = []): array
    {
        return $this->filterMaster($this->groups, $filters, fn (array $row): SpecificationGroupRecord => $this->toGroup($row));
    }

    public function findGroupByPublicId(string $publicId, bool $includeDeleted = false): ?SpecificationGroupRecord
    {
        $row = $this->findRow($this->groups, $publicId, $includeDeleted);

        return $row === null ? null : $this->toGroup($row);
    }

    public function findGroupByCode(string $code): ?SpecificationGroupRecord
    {
        foreach ($this->groups as $row) {
            if (($row['deletedAtUtc'] ?? null) === null && strtoupper((string) $row['code']) === strtoupper($code)) {
                return $this->toGroup($row);
            }
        }

        return null;
    }

    public function createGroup(array $data, UserId $actorUserId): SpecificationGroupRecord
    {
        $row = $this->newRow($data);
        $this->groups[] = $row;

        return $this->toGroup($row);
    }

    public function updateGroup(string $publicId, array $data, string $expectedRowVersion, UserId $actorUserId): SpecificationGroupRecord
    {
        return $this->toGroup($this->mutate($this->groups, $publicId, $expectedRowVersion, 'group', $data));
    }

    public function setGroupActive(string $publicId, bool $isActive, string $expectedRowVersion, UserId $actorUserId): SpecificationGroupRecord
    {
        return $this->toGroup($this->mutate($this->groups, $publicId, $expectedRowVersion, 'group', ['isActive' => $isActive]));
    }

    public function softDeleteGroup(string $publicId, string $expectedRowVersion, UserId $actorUserId): SpecificationGroupRecord
    {
        return $this->toGroup($this->mutate($this->groups, $publicId, $expectedRowVersion, 'group', [
            'deletedAtUtc' => gmdate('c'),
            'isActive' => false,
        ]));
    }

    public function restoreGroup(string $publicId, string $expectedRowVersion, UserId $actorUserId): SpecificationGroupRecord
    {
        return $this->toGroup($this->mutate($this->groups, $publicId, $expectedRowVersion, 'group', [
            'deletedAtUtc' => null,
            'isActive' => true,
        ], true));
    }

    public function reorderGroups(array $orderedPublicIds, UserId $actorUserId): void
    {
        $this->reorder($this->groups, $orderedPublicIds);
    }

    public function getGroupUsage(string $publicId): SpecificationUsage
    {
        $defs = array_filter(
            $this->definitions,
            static fn (array $row): bool => ($row['groupPublicId'] ?? null) === $publicId && ($row['deletedAtUtc'] ?? null) === null,
        );
        $active = array_filter($defs, static fn (array $row): bool => (bool) ($row['isActive'] ?? true));

        return new SpecificationUsage(
            publicId: $publicId,
            level: 'group',
            definitionCount: count($defs),
            activeDefinitionCount: count($active),
        );
    }

    public function groupCodeExists(string $code, ?string $excludePublicId = null): bool
    {
        return $this->codeExists($this->groups, $code, $excludePublicId);
    }

    public function listUnitsAdmin(array $filters = []): array
    {
        return $this->filterMaster($this->units, $filters, fn (array $row): SpecificationUnitRecord => $this->toUnit($row));
    }

    public function findUnitByPublicId(string $publicId, bool $includeDeleted = false): ?SpecificationUnitRecord
    {
        $row = $this->findRow($this->units, $publicId, $includeDeleted);

        return $row === null ? null : $this->toUnit($row);
    }

    public function createUnit(array $data, UserId $actorUserId): SpecificationUnitRecord
    {
        $row = $this->newRow($data);
        $row['symbol'] = $data['symbol'];
        $this->units[] = $row;

        return $this->toUnit($row);
    }

    public function updateUnit(string $publicId, array $data, string $expectedRowVersion, UserId $actorUserId): SpecificationUnitRecord
    {
        return $this->toUnit($this->mutate($this->units, $publicId, $expectedRowVersion, 'unit', $data));
    }

    public function setUnitActive(string $publicId, bool $isActive, string $expectedRowVersion, UserId $actorUserId): SpecificationUnitRecord
    {
        return $this->toUnit($this->mutate($this->units, $publicId, $expectedRowVersion, 'unit', ['isActive' => $isActive]));
    }

    public function softDeleteUnit(string $publicId, string $expectedRowVersion, UserId $actorUserId): SpecificationUnitRecord
    {
        return $this->toUnit($this->mutate($this->units, $publicId, $expectedRowVersion, 'unit', [
            'deletedAtUtc' => gmdate('c'),
            'isActive' => false,
        ]));
    }

    public function restoreUnit(string $publicId, string $expectedRowVersion, UserId $actorUserId): SpecificationUnitRecord
    {
        return $this->toUnit($this->mutate($this->units, $publicId, $expectedRowVersion, 'unit', [
            'deletedAtUtc' => null,
            'isActive' => true,
        ], true));
    }

    public function reorderUnits(array $orderedPublicIds, UserId $actorUserId): void
    {
        $this->reorder($this->units, $orderedPublicIds);
    }

    public function getUnitUsage(string $publicId): SpecificationUsage
    {
        $unit = $this->findRow($this->units, $publicId, true);
        $symbol = $unit['symbol'] ?? null;
        $count = 0;
        foreach ($this->definitions as $row) {
            if (($row['deletedAtUtc'] ?? null) !== null) {
                continue;
            }
            if (($row['unitPublicId'] ?? null) === $publicId || ($symbol !== null && ($row['unit'] ?? null) === $symbol)) {
                ++$count;
            }
        }

        return new SpecificationUsage(publicId: $publicId, level: 'unit', definitionCount: $count);
    }

    public function unitCodeExists(string $code, ?string $excludePublicId = null): bool
    {
        return $this->codeExists($this->units, $code, $excludePublicId);
    }

    public function listDefinitionsAdmin(array $filters = []): array
    {
        $out = [];
        foreach ($this->definitions as $row) {
            $record = $this->toDefinition($row);
            if (!($filters['includeDeleted'] ?? false) && $record->isDeleted) {
                continue;
            }
            if (array_key_exists('activeOnly', $filters) && $filters['activeOnly'] !== null && $record->isActive !== (bool) $filters['activeOnly']) {
                continue;
            }
            if (isset($filters['groupPublicId']) && is_string($filters['groupPublicId']) && $record->groupPublicId !== $filters['groupPublicId']) {
                continue;
            }
            if (isset($filters['scopeCode']) && is_string($filters['scopeCode']) && $record->scopeCode !== $filters['scopeCode']) {
                continue;
            }
            if (isset($filters['search']) && is_string($filters['search']) && trim($filters['search']) !== '') {
                $q = strtolower(trim($filters['search']));
                if (!str_contains(strtolower($record->code), $q) && !str_contains(strtolower($record->name), $q)) {
                    continue;
                }
            }
            $out[] = $record;
        }
        usort($out, static fn ($a, $b) => [$a->displayOrder, $a->name] <=> [$b->displayOrder, $b->name]);

        return $out;
    }

    public function findDefinitionByPublicId(string $publicId, bool $includeDeleted = false): ?SpecificationDefinitionRecord
    {
        $row = $this->findRow($this->definitions, $publicId, $includeDeleted);

        return $row === null ? null : $this->toDefinition($row);
    }

    public function findDefinitionByCode(string $code): ?SpecificationDefinitionRecord
    {
        foreach ($this->definitions as $row) {
            if (($row['deletedAtUtc'] ?? null) === null && strtoupper((string) $row['code']) === strtoupper($code)) {
                return $this->toDefinition($row);
            }
        }

        return null;
    }

    public function createDefinition(array $data, UserId $actorUserId): SpecificationDefinitionRecord
    {
        $row = $this->newRow($data);
        foreach ([
            'groupPublicId', 'dataTypeCode', 'scopeCode', 'unit', 'unitPublicId', 'isRequired', 'isFilterable',
            'isSearchable', 'isComparable', 'minimumValue', 'maximumValue', 'decimalPrecision', 'validationRulesJson',
        ] as $key) {
            if (array_key_exists($key, $data)) {
                $row[$key] = $data[$key];
            }
        }
        $this->definitions[] = $row;

        return $this->toDefinition($row);
    }

    public function updateDefinition(string $publicId, array $data, string $expectedRowVersion, UserId $actorUserId): SpecificationDefinitionRecord
    {
        return $this->toDefinition($this->mutate($this->definitions, $publicId, $expectedRowVersion, 'definition', $data));
    }

    public function setDefinitionActive(string $publicId, bool $isActive, string $expectedRowVersion, UserId $actorUserId): SpecificationDefinitionRecord
    {
        return $this->toDefinition($this->mutate($this->definitions, $publicId, $expectedRowVersion, 'definition', ['isActive' => $isActive]));
    }

    public function softDeleteDefinition(string $publicId, string $expectedRowVersion, UserId $actorUserId): SpecificationDefinitionRecord
    {
        return $this->toDefinition($this->mutate($this->definitions, $publicId, $expectedRowVersion, 'definition', [
            'deletedAtUtc' => gmdate('c'),
            'isActive' => false,
        ]));
    }

    public function restoreDefinition(string $publicId, string $expectedRowVersion, UserId $actorUserId): SpecificationDefinitionRecord
    {
        return $this->toDefinition($this->mutate($this->definitions, $publicId, $expectedRowVersion, 'definition', [
            'deletedAtUtc' => null,
            'isActive' => true,
        ], true));
    }

    public function reorderDefinitions(?string $groupPublicId, array $orderedPublicIds, UserId $actorUserId): void
    {
        $this->reorder($this->definitions, $orderedPublicIds, $groupPublicId === null ? null : static fn (array $row): bool => ($row['groupPublicId'] ?? null) === $groupPublicId);
    }

    public function getDefinitionUsage(string $publicId): SpecificationUsage
    {
        $product = 0;
        $variant = 0;
        foreach ($this->productValues as $values) {
            foreach ($values as $value) {
                if (($value['definitionPublicId'] ?? '') === $publicId) {
                    ++$product;
                }
            }
        }
        foreach ($this->variantValues as $values) {
            foreach ($values as $value) {
                if (($value['definitionPublicId'] ?? '') === $publicId) {
                    ++$variant;
                }
            }
        }
        $options = count(array_filter(
            $this->options,
            static fn (array $row): bool => ($row['definitionPublicId'] ?? null) === $publicId && ($row['deletedAtUtc'] ?? null) === null,
        ));

        return new SpecificationUsage(
            publicId: $publicId,
            level: 'definition',
            productValueCount: $product,
            variantValueCount: $variant,
            optionCount: $options,
        );
    }

    public function definitionCodeExists(string $code, ?string $excludePublicId = null): bool
    {
        return $this->codeExists($this->definitions, $code, $excludePublicId);
    }

    public function listOptionsAdmin(array $filters = []): array
    {
        $out = [];
        foreach ($this->options as $row) {
            $record = $this->toOption($row);
            if (!($filters['includeDeleted'] ?? false) && $record->isDeleted) {
                continue;
            }
            if (isset($filters['definitionPublicId']) && is_string($filters['definitionPublicId']) && $record->definitionPublicId !== $filters['definitionPublicId']) {
                continue;
            }
            if (array_key_exists('activeOnly', $filters) && $filters['activeOnly'] !== null && $record->isActive !== (bool) $filters['activeOnly']) {
                continue;
            }
            $out[] = $record;
        }
        usort($out, static fn ($a, $b) => [$a->displayOrder, $a->name] <=> [$b->displayOrder, $b->name]);

        return $out;
    }

    public function findOptionByPublicId(string $publicId, bool $includeDeleted = false): ?SpecificationOptionRecord
    {
        $row = $this->findRow($this->options, $publicId, $includeDeleted);

        return $row === null ? null : $this->toOption($row);
    }

    public function findOptionByDefinitionAndCode(string $definitionPublicId, string $code): ?SpecificationOptionRecord
    {
        foreach ($this->options as $row) {
            if (
                ($row['deletedAtUtc'] ?? null) === null
                && ($row['definitionPublicId'] ?? null) === $definitionPublicId
                && strtoupper((string) $row['code']) === strtoupper($code)
            ) {
                return $this->toOption($row);
            }
        }

        return null;
    }

    public function createOption(array $data, UserId $actorUserId): SpecificationOptionRecord
    {
        $row = $this->newRow($data);
        $row['definitionPublicId'] = $data['definitionPublicId'];
        $row['value'] = $data['value'] ?? null;
        $this->options[] = $row;

        return $this->toOption($row);
    }

    public function updateOption(string $publicId, array $data, string $expectedRowVersion, UserId $actorUserId): SpecificationOptionRecord
    {
        return $this->toOption($this->mutate($this->options, $publicId, $expectedRowVersion, 'option', $data));
    }

    public function setOptionActive(string $publicId, bool $isActive, string $expectedRowVersion, UserId $actorUserId): SpecificationOptionRecord
    {
        return $this->toOption($this->mutate($this->options, $publicId, $expectedRowVersion, 'option', ['isActive' => $isActive]));
    }

    public function softDeleteOption(string $publicId, string $expectedRowVersion, UserId $actorUserId): SpecificationOptionRecord
    {
        return $this->toOption($this->mutate($this->options, $publicId, $expectedRowVersion, 'option', [
            'deletedAtUtc' => gmdate('c'),
            'isActive' => false,
        ]));
    }

    public function restoreOption(string $publicId, string $expectedRowVersion, UserId $actorUserId): SpecificationOptionRecord
    {
        return $this->toOption($this->mutate($this->options, $publicId, $expectedRowVersion, 'option', [
            'deletedAtUtc' => null,
            'isActive' => true,
        ], true));
    }

    public function reorderOptions(string $definitionPublicId, array $orderedPublicIds, UserId $actorUserId): void
    {
        $this->reorder(
            $this->options,
            $orderedPublicIds,
            static fn (array $row): bool => ($row['definitionPublicId'] ?? null) === $definitionPublicId,
        );
    }

    public function getOptionUsage(string $publicId): SpecificationUsage
    {
        $product = 0;
        $variant = 0;
        foreach ($this->productValues as $values) {
            foreach ($values as $value) {
                if (($value['optionPublicId'] ?? null) === $publicId || in_array($publicId, $value['optionPublicIds'] ?? [], true)) {
                    ++$product;
                }
            }
        }
        foreach ($this->variantValues as $values) {
            foreach ($values as $value) {
                if (($value['optionPublicId'] ?? null) === $publicId || in_array($publicId, $value['optionPublicIds'] ?? [], true)) {
                    ++$variant;
                }
            }
        }

        return new SpecificationUsage(publicId: $publicId, level: 'option', productValueCount: $product, variantValueCount: $variant);
    }

    public function optionCodeExists(string $definitionPublicId, string $code, ?string $excludePublicId = null): bool
    {
        foreach ($this->options as $row) {
            if (($row['definitionPublicId'] ?? null) !== $definitionPublicId) {
                continue;
            }
            if (($row['deletedAtUtc'] ?? null) !== null) {
                continue;
            }
            if (strtoupper((string) $row['code']) !== strtoupper($code)) {
                continue;
            }
            if ($excludePublicId !== null && $row['publicId'] === $excludePublicId) {
                continue;
            }

            return true;
        }

        return false;
    }

    // --- helpers ----------------------------------------------------------

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function newRow(array $data): array
    {
        return [
            'id' => $this->seq++,
            'publicId' => isset($data['publicId']) && is_string($data['publicId']) && $data['publicId'] !== ''
                ? $data['publicId']
                : sprintf('00000000-0000-4000-8000-%012d', $this->seq),
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'displayOrder' => $data['displayOrder'] ?? 0,
            'isActive' => $data['isActive'] ?? true,
            'deletedAtUtc' => null,
            'rowVersion' => $this->nextVersion(),
            'createdAtUtc' => gmdate('c'),
            'updatedAtUtc' => gmdate('c'),
        ];
    }

    private function nextVersion(): string
    {
        return base64_encode(pack('J', $this->rowVersion++));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function mutate(array &$rows, string $publicId, string $expectedRowVersion, string $level, array $data, bool $includeDeleted = false): array
    {
        foreach ($rows as $i => $row) {
            if ($row['publicId'] !== $publicId) {
                continue;
            }
            if (!$includeDeleted && ($row['deletedAtUtc'] ?? null) !== null) {
                throw SpecificationMasterNotFound::for($level, $publicId);
            }
            if ($row['rowVersion'] !== $expectedRowVersion) {
                throw SpecificationMasterConcurrencyConflict::for($level, $publicId);
            }
            foreach ($data as $key => $value) {
                $rows[$i][$key] = $value;
            }
            $rows[$i]['rowVersion'] = $this->nextVersion();
            $rows[$i]['updatedAtUtc'] = gmdate('c');

            return $rows[$i];
        }
        throw SpecificationMasterNotFound::for($level, $publicId);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private function findRow(array $rows, string $publicId, bool $includeDeleted): ?array
    {
        foreach ($rows as $row) {
            if ($row['publicId'] !== $publicId) {
                continue;
            }
            if (!$includeDeleted && ($row['deletedAtUtc'] ?? null) !== null) {
                return null;
            }

            return $row;
        }

        return null;
    }

    /** @param list<array<string, mixed>> $rows */
    private function codeExists(array $rows, string $code, ?string $excludePublicId): bool
    {
        foreach ($rows as $row) {
            if (($row['deletedAtUtc'] ?? null) !== null) {
                continue;
            }
            if (strtoupper((string) $row['code']) !== strtoupper($code)) {
                continue;
            }
            if ($excludePublicId !== null && $row['publicId'] === $excludePublicId) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $orderedPublicIds
     * @param (callable(array<string,mixed>):bool)|null $scope
     */
    private function reorder(array &$rows, array $orderedPublicIds, ?callable $scope = null): void
    {
        $order = array_flip($orderedPublicIds);
        foreach ($rows as $i => $row) {
            if ($scope !== null && !$scope($row)) {
                continue;
            }
            if (isset($order[$row['publicId']])) {
                $rows[$i]['displayOrder'] = ($order[$row['publicId']] + 1) * 10;
                $rows[$i]['rowVersion'] = $this->nextVersion();
            }
        }
    }

    /**
     * @template T
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $filters
     * @param callable(array<string,mixed>):T $map
     * @return list<T>
     */
    private function filterMaster(array $rows, array $filters, callable $map): array
    {
        $out = [];
        foreach ($rows as $row) {
            $record = $map($row);
            $isDeleted = (bool) (is_object($record) ? $record->isDeleted : false);
            $isActive = (bool) (is_object($record) ? $record->isActive : true);
            $code = (string) (is_object($record) ? $record->code : '');
            $name = (string) (is_object($record) ? $record->name : '');
            if (!($filters['includeDeleted'] ?? false) && $isDeleted) {
                continue;
            }
            if (array_key_exists('activeOnly', $filters) && $filters['activeOnly'] !== null && $isActive !== (bool) $filters['activeOnly']) {
                continue;
            }
            if (isset($filters['search']) && is_string($filters['search']) && trim($filters['search']) !== '') {
                $q = strtolower(trim($filters['search']));
                if (!str_contains(strtolower($code), $q) && !str_contains(strtolower($name), $q)) {
                    continue;
                }
            }
            $out[] = $record;
        }
        usort($out, static function ($a, $b): int {
            $ao = is_object($a) ? (int) $a->displayOrder : 0;
            $bo = is_object($b) ? (int) $b->displayOrder : 0;
            $an = is_object($a) ? (string) $a->name : '';
            $bn = is_object($b) ? (string) $b->name : '';

            return [$ao, $an] <=> [$bo, $bn];
        });

        return $out;
    }

    /** @param array<string, mixed> $row */
    private function toGroup(array $row): SpecificationGroupRecord
    {
        $usage = $this->getGroupUsage($row['publicId']);

        return new SpecificationGroupRecord(
            publicId: $row['publicId'],
            code: $row['code'],
            name: $row['name'],
            description: $row['description'] ?? null,
            displayOrder: (int) ($row['displayOrder'] ?? 0),
            isActive: (bool) ($row['isActive'] ?? true),
            isDeleted: ($row['deletedAtUtc'] ?? null) !== null,
            rowVersion: $row['rowVersion'],
            createdAtUtc: $row['createdAtUtc'] ?? null,
            updatedAtUtc: $row['updatedAtUtc'] ?? null,
            definitionCount: $usage->definitionCount,
            activeDefinitionCount: $usage->activeDefinitionCount,
        );
    }

    /** @param array<string, mixed> $row */
    private function toUnit(array $row): SpecificationUnitRecord
    {
        $usage = $this->getUnitUsage($row['publicId']);

        return new SpecificationUnitRecord(
            publicId: $row['publicId'],
            code: $row['code'],
            name: $row['name'],
            symbol: (string) ($row['symbol'] ?? ''),
            description: $row['description'] ?? null,
            displayOrder: (int) ($row['displayOrder'] ?? 0),
            isActive: (bool) ($row['isActive'] ?? true),
            isDeleted: ($row['deletedAtUtc'] ?? null) !== null,
            rowVersion: $row['rowVersion'],
            createdAtUtc: $row['createdAtUtc'] ?? null,
            updatedAtUtc: $row['updatedAtUtc'] ?? null,
            definitionCount: $usage->definitionCount,
        );
    }

    /** @param array<string, mixed> $row */
    private function toDefinition(array $row): SpecificationDefinitionRecord
    {
        $group = null;
        if (!empty($row['groupPublicId'])) {
            $group = $this->findGroupByPublicId((string) $row['groupPublicId'], true);
        }
        $options = $this->listOptionsAdmin([
            'definitionPublicId' => $row['publicId'],
            'includeDeleted' => true,
        ]);
        $usage = $this->getDefinitionUsage($row['publicId']);

        return new SpecificationDefinitionRecord(
            publicId: $row['publicId'],
            code: $row['code'],
            name: $row['name'],
            description: $row['description'] ?? null,
            groupPublicId: $row['groupPublicId'] ?? null,
            groupCode: $group?->code,
            groupName: $group?->name,
            dataTypeCode: (string) ($row['dataTypeCode'] ?? 'text'),
            scopeCode: (string) ($row['scopeCode'] ?? 'product'),
            unit: $row['unit'] ?? null,
            unitPublicId: $row['unitPublicId'] ?? null,
            displayOrder: (int) ($row['displayOrder'] ?? 0),
            isRequired: (bool) ($row['isRequired'] ?? false),
            isFilterable: (bool) ($row['isFilterable'] ?? false),
            isSearchable: (bool) ($row['isSearchable'] ?? false),
            isComparable: (bool) ($row['isComparable'] ?? false),
            minimumValue: isset($row['minimumValue']) ? ($row['minimumValue'] === null ? null : (string) $row['minimumValue']) : null,
            maximumValue: isset($row['maximumValue']) ? ($row['maximumValue'] === null ? null : (string) $row['maximumValue']) : null,
            decimalPrecision: isset($row['decimalPrecision']) ? ($row['decimalPrecision'] === null ? null : (int) $row['decimalPrecision']) : null,
            validationRules: SpecificationValidationRules::fromJson($row['validationRulesJson'] ?? null),
            isActive: (bool) ($row['isActive'] ?? true),
            isDeleted: ($row['deletedAtUtc'] ?? null) !== null,
            rowVersion: $row['rowVersion'],
            options: $options,
            createdAtUtc: $row['createdAtUtc'] ?? null,
            updatedAtUtc: $row['updatedAtUtc'] ?? null,
            productValueCount: $usage->productValueCount,
            variantValueCount: $usage->variantValueCount,
        );
    }

    /** @param array<string, mixed> $row */
    private function toOption(array $row): SpecificationOptionRecord
    {
        return new SpecificationOptionRecord(
            publicId: $row['publicId'],
            definitionPublicId: (string) $row['definitionPublicId'],
            code: $row['code'],
            name: $row['name'],
            value: $row['value'] ?? null,
            displayOrder: (int) ($row['displayOrder'] ?? 0),
            isActive: (bool) ($row['isActive'] ?? true),
            isDeleted: ($row['deletedAtUtc'] ?? null) !== null,
            rowVersion: $row['rowVersion'],
            createdAtUtc: $row['createdAtUtc'] ?? null,
            updatedAtUtc: $row['updatedAtUtc'] ?? null,
        );
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function toLegacyDefinition(array $row): array
    {
        $group = null;
        if (!empty($row['groupPublicId'])) {
            $group = $this->findGroupByPublicId((string) $row['groupPublicId'], true);
        }
        $options = [];
        foreach ($this->options as $option) {
            if (($option['definitionPublicId'] ?? null) !== $row['publicId'] || ($option['deletedAtUtc'] ?? null) !== null) {
                continue;
            }
            if (!($option['isActive'] ?? true)) {
                continue;
            }
            $options[] = [
                'publicId' => $option['publicId'],
                'optionCode' => $option['code'],
                'optionLabel' => $option['name'],
                'displayOrder' => (int) ($option['displayOrder'] ?? 0),
            ];
        }

        return [
            'publicId' => $row['publicId'],
            'groupCode' => $group?->code,
            'definitionCode' => $row['code'],
            'definitionName' => $row['name'],
            'dataTypeCode' => (string) ($row['dataTypeCode'] ?? 'text'),
            'scopeCode' => (string) ($row['scopeCode'] ?? 'product'),
            'unit' => $row['unit'] ?? null,
            'isRequired' => (bool) ($row['isRequired'] ?? false),
            'displayOrder' => (int) ($row['displayOrder'] ?? 0),
            'options' => $options,
        ];
    }
}
