<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\ImportSpecificationDefinitionsCommand;
use TriCab\Core\Product\Application\Command\ReorderSpecificationCommand;
use TriCab\Core\Product\Application\Command\SpecificationStatusCommand;
use TriCab\Core\Product\Application\Command\UpsertSpecificationDefinitionCommand;
use TriCab\Core\Product\Application\DTO\SpecificationDefinitionRecord;
use TriCab\Core\Product\Application\DTO\SpecificationImportResult;
use TriCab\Core\Product\Application\DTO\SpecificationUsage;
use TriCab\Core\Product\Application\DTO\SpecificationValidationRules;
use TriCab\Core\Product\Application\Exception\DuplicateSpecificationCode;
use TriCab\Core\Product\Application\Exception\SpecificationMasterInUse;
use TriCab\Core\Product\Application\Exception\SpecificationMasterNotFound;
use TriCab\Core\Product\Contract\ProductSpecificationRepository;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\ValueObject\DisplayOrder;
use TriCab\Core\Product\Domain\ValueObject\SpecificationDataType;
use TriCab\Core\Product\Domain\ValueObject\SpecificationScope;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class SpecificationDefinitionService
{
    public function __construct(
        private readonly ProductSpecificationRepository $specifications,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    /** @param array{search?: ?string, activeOnly?: ?bool, includeDeleted?: bool, groupPublicId?: ?string, scopeCode?: ?string} $filters
     * @return list<\TriCab\Core\Product\Application\DTO\SpecificationDefinitionRecord>
     */
    public function list(array $filters = []): array
    {
        return $this->specifications->listDefinitionsAdmin($filters);
    }

    public function get(string $publicId): SpecificationDefinitionRecord
    {
        return $this->require($publicId, true);
    }

    public function usage(string $publicId): SpecificationUsage
    {
        $this->require($publicId, true);

        return $this->specifications->getDefinitionUsage($publicId);
    }

    public function create(ActorContext $actor, UpsertSpecificationDefinitionCommand $command): SpecificationDefinitionRecord
    {
        return $this->transaction->run(function () use ($actor, $command): SpecificationDefinitionRecord {
            $payload = $this->normalizePayload($command);
            $code = is_string($payload['code']) ? $payload['code'] : '';
            if ($this->specifications->definitionCodeExists($code)) {
                throw DuplicateSpecificationCode::forCode($code);
            }
            $created = $this->specifications->createDefinition($payload, $this->audit->actorUserId($actor));
            $record = $this->require($created->publicId);
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'specification-definition',
                entityId: null,
                actionCode: 'created',
                newValues: ['publicId' => $record->publicId, 'code' => $record->code],
            );

            return $record;
        });
    }

    public function update(ActorContext $actor, UpsertSpecificationDefinitionCommand $command): SpecificationDefinitionRecord
    {
        return $this->transaction->run(function () use ($actor, $command): SpecificationDefinitionRecord {
            $publicId = $command->definitionPublicId ?? '';
            if ($publicId === '' || $command->expectedRowVersion === null || $command->expectedRowVersion === '') {
                throw InvalidProductValue::for('expectedRowVersion', (string) $command->expectedRowVersion);
            }
            $this->require($publicId);
            $payload = $this->normalizePayload($command);
            $code = is_string($payload['code']) ? $payload['code'] : '';
            if ($this->specifications->definitionCodeExists($code, $publicId)) {
                throw DuplicateSpecificationCode::forCode($code);
            }
            $updated = $this->specifications->updateDefinition(
                $publicId,
                $payload,
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'specification-definition',
                entityId: null,
                actionCode: 'updated',
                newValues: ['publicId' => $updated->publicId, 'code' => $updated->code],
            );

            return $updated;
        });
    }

    public function activate(ActorContext $actor, SpecificationStatusCommand $command): SpecificationDefinitionRecord
    {
        return $this->setActive($actor, $command, true);
    }

    public function deactivate(ActorContext $actor, SpecificationStatusCommand $command): SpecificationDefinitionRecord
    {
        return $this->setActive($actor, $command, false);
    }

    public function archive(ActorContext $actor, SpecificationStatusCommand $command): SpecificationDefinitionRecord
    {
        return $this->transaction->run(function () use ($actor, $command): SpecificationDefinitionRecord {
            $this->require($command->publicId);
            $usage = $this->specifications->getDefinitionUsage($command->publicId);
            if ($usage->hasBlockingReferencesForArchive()) {
                throw SpecificationMasterInUse::cannotArchive('Specification definition', $usage);
            }
            $archived = $this->specifications->softDeleteDefinition(
                $command->publicId,
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'specification-definition',
                entityId: null,
                actionCode: 'archived',
                newValues: ['publicId' => $archived->publicId],
            );

            return $archived;
        });
    }

    public function restore(ActorContext $actor, SpecificationStatusCommand $command): SpecificationDefinitionRecord
    {
        return $this->transaction->run(function () use ($actor, $command): SpecificationDefinitionRecord {
            $this->require($command->publicId, true);
            $restored = $this->specifications->restoreDefinition(
                $command->publicId,
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'specification-definition',
                entityId: null,
                actionCode: 'restored',
                newValues: ['publicId' => $restored->publicId],
            );

            return $restored;
        });
    }

    public function reorder(ActorContext $actor, ReorderSpecificationCommand $command): void
    {
        $this->transaction->run(function () use ($actor, $command): void {
            $this->specifications->reorderDefinitions(
                $command->parentPublicId,
                $command->orderedPublicIds,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'specification-definition',
                entityId: null,
                actionCode: 'reordered',
                newValues: ['orderedPublicIds' => $command->orderedPublicIds],
            );
        });
    }

    /** @return list<array<string, mixed>> */
    public function export(): array
    {
        $items = [];
        foreach ($this->specifications->listDefinitionsAdmin(['includeDeleted' => false]) as $definition) {
            $options = [];
            foreach ($definition->options as $option) {
                if ($option->isDeleted) {
                    continue;
                }
                $options[] = [
                    'code' => $option->code,
                    'name' => $option->name,
                    'value' => $option->value,
                    'displayOrder' => $option->displayOrder,
                    'isActive' => $option->isActive,
                ];
            }
            $items[] = [
                'code' => $definition->code,
                'name' => $definition->name,
                'description' => $definition->description,
                'groupCode' => $definition->groupCode,
                'dataTypeCode' => $definition->dataTypeCode,
                'scopeCode' => $definition->scopeCode,
                'unit' => $definition->unit,
                'displayOrder' => $definition->displayOrder,
                'isRequired' => $definition->isRequired,
                'isFilterable' => $definition->isFilterable,
                'isSearchable' => $definition->isSearchable,
                'isComparable' => $definition->isComparable,
                'minimumValue' => $definition->minimumValue,
                'maximumValue' => $definition->maximumValue,
                'decimalPrecision' => $definition->decimalPrecision,
                'validationRules' => $definition->validationRules->toArray(),
                'isActive' => $definition->isActive,
                'options' => $options,
            ];
        }

        return $items;
    }

    public function import(ActorContext $actor, ImportSpecificationDefinitionsCommand $command): SpecificationImportResult
    {
        return $this->transaction->run(function () use ($actor, $command): SpecificationImportResult {
            $created = 0;
            $updated = 0;
            $skipped = 0;
            $errors = [];

            foreach ($command->definitions as $index => $row) {
                try {
                    $code = SpecificationMasterSupport::normalizeCode(self::stringFromMixed($row['code'] ?? null));
                    $name = SpecificationMasterSupport::requireName(self::stringFromMixed($row['name'] ?? null));
                    $dataType = SpecificationDataType::fromString(self::stringFromMixed($row['dataTypeCode'] ?? null));
                    $scope = SpecificationScope::fromString(self::stringFromMixed($row['scopeCode'] ?? null, 'product'));
                    $groupPublicId = null;
                    $groupCode = isset($row['groupCode']) && is_string($row['groupCode']) ? strtoupper(trim($row['groupCode'])) : null;
                    if ($groupCode !== null && $groupCode !== '') {
                        $group = $this->specifications->findGroupByCode($groupCode);
                        if ($group === null) {
                            throw InvalidProductValue::for('groupCode', $groupCode);
                        }
                        $groupPublicId = $group->publicId;
                    }
                    $rulesRaw = $row['validationRules'] ?? null;
                    /** @var array<string, mixed>|null $rulesArray */
                    $rulesArray = is_array($rulesRaw) ? $rulesRaw : null;
                    $rules = SpecificationValidationRules::fromArray($rulesArray);
                    $minRaw = $row['minimumValue'] ?? null;
                    $maxRaw = $row['maximumValue'] ?? null;
                    $payload = [
                        'code' => $code,
                        'name' => $name,
                        'description' => SpecificationMasterSupport::normalizeDescription(
                            isset($row['description']) && is_string($row['description']) ? $row['description'] : null,
                        ),
                        'groupPublicId' => $groupPublicId,
                        'dataTypeCode' => $dataType->toString(),
                        'scopeCode' => $scope->toString(),
                        'unit' => isset($row['unit']) && is_string($row['unit']) && trim($row['unit']) !== '' ? trim($row['unit']) : null,
                        'unitPublicId' => null,
                        'displayOrder' => isset($row['displayOrder']) && is_numeric($row['displayOrder']) ? (int) $row['displayOrder'] : 0,
                        'isRequired' => (bool) ($row['isRequired'] ?? false),
                        'isFilterable' => (bool) ($row['isFilterable'] ?? false),
                        'isSearchable' => (bool) ($row['isSearchable'] ?? false),
                        'isComparable' => (bool) ($row['isComparable'] ?? false),
                        'minimumValue' => self::nullableNumericString($minRaw),
                        'maximumValue' => self::nullableNumericString($maxRaw),
                        'decimalPrecision' => isset($row['decimalPrecision']) && is_numeric($row['decimalPrecision'])
                            ? (int) $row['decimalPrecision'] : null,
                        'validationRulesJson' => $rules->toJson(),
                        'isActive' => array_key_exists('isActive', $row) ? (bool) $row['isActive'] : true,
                    ];
                    DisplayOrder::fromInt($payload['displayOrder']);

                    $existing = $this->specifications->findDefinitionByCode($code);
                    if ($existing === null) {
                        $createdRecord = $this->specifications->createDefinition($payload, $this->audit->actorUserId($actor));
                        $this->importOptions($actor, $createdRecord->publicId, $dataType, $row['options'] ?? []);
                        ++$created;
                    } else {
                        $this->specifications->updateDefinition(
                            $existing->publicId,
                            $payload,
                            $existing->rowVersion,
                            $this->audit->actorUserId($actor),
                        );
                        $this->importOptions($actor, $existing->publicId, $dataType, $row['options'] ?? []);
                        ++$updated;
                    }
                } catch (\Throwable $e) {
                    $errors[] = sprintf('Row %d (%s): %s', $index, self::stringFromMixed($row['code'] ?? null, '?'), $e->getMessage());
                    ++$skipped;
                }
            }

            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'specification-definition',
                entityId: null,
                actionCode: 'imported',
                newValues: ['created' => $created, 'updated' => $updated, 'skipped' => $skipped],
            );

            return new SpecificationImportResult($created, $updated, $skipped, $errors);
        });
    }

    /**
     * @param mixed $optionsRaw
     */
    private function importOptions(ActorContext $actor, string $definitionPublicId, SpecificationDataType $dataType, mixed $optionsRaw): void
    {
        if (!$dataType->isSelect() || !is_array($optionsRaw)) {
            return;
        }
        foreach ($optionsRaw as $optionRaw) {
            if (!is_array($optionRaw)) {
                continue;
            }
            /** @var array<string, mixed> $optionRow */
            $optionRow = $optionRaw;
            $code = SpecificationMasterSupport::normalizeCode(self::stringFromMixed($optionRow['code'] ?? null));
            $name = SpecificationMasterSupport::requireName(self::stringFromMixed($optionRow['name'] ?? null));
            $payload = [
                'definitionPublicId' => $definitionPublicId,
                'code' => $code,
                'name' => $name,
                'value' => SpecificationMasterSupport::normalizeDescription(
                    isset($optionRow['value']) && is_string($optionRow['value']) ? $optionRow['value'] : null,
                ),
                'displayOrder' => isset($optionRow['displayOrder']) && is_numeric($optionRow['displayOrder'])
                    ? (int) $optionRow['displayOrder'] : 0,
                'isActive' => array_key_exists('isActive', $optionRow) ? (bool) $optionRow['isActive'] : true,
            ];
            $existing = $this->specifications->findOptionByDefinitionAndCode($definitionPublicId, $code);
            if ($existing === null) {
                $this->specifications->createOption($payload, $this->audit->actorUserId($actor));
            } else {
                $this->specifications->updateOption(
                    $existing->publicId,
                    $payload,
                    $existing->rowVersion,
                    $this->audit->actorUserId($actor),
                );
            }
        }
    }

    private static function stringFromMixed(mixed $value, string $default = ''): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $default;
    }

    private static function nullableNumericString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function normalizePayload(UpsertSpecificationDefinitionCommand $command): array
    {
        $code = SpecificationMasterSupport::normalizeCode($command->code);
        $name = SpecificationMasterSupport::requireName($command->name);
        $dataType = SpecificationDataType::fromString($command->dataTypeCode);
        $scope = SpecificationScope::fromString($command->scopeCode);
        DisplayOrder::fromInt($command->displayOrder);
        if ($command->groupPublicId !== null && $command->groupPublicId !== '') {
            $group = $this->specifications->findGroupByPublicId($command->groupPublicId);
            if ($group === null) {
                throw SpecificationMasterNotFound::for('group', $command->groupPublicId);
            }
        }
        $unit = $command->unit;
        if ($command->unitPublicId !== null && $command->unitPublicId !== '') {
            $unitRecord = $this->specifications->findUnitByPublicId($command->unitPublicId);
            if ($unitRecord === null) {
                throw SpecificationMasterNotFound::for('unit', $command->unitPublicId);
            }
            $unit = $unitRecord->symbol;
        }
        $rules = $command->validationRules ?? new SpecificationValidationRules();

        return [
            'code' => $code,
            'name' => $name,
            'description' => SpecificationMasterSupport::normalizeDescription($command->description),
            'groupPublicId' => $command->groupPublicId !== null && $command->groupPublicId !== '' ? $command->groupPublicId : null,
            'dataTypeCode' => $dataType->toString(),
            'scopeCode' => $scope->toString(),
            'unit' => $unit !== null && trim($unit) !== '' ? trim($unit) : null,
            'unitPublicId' => $command->unitPublicId !== null && $command->unitPublicId !== '' ? $command->unitPublicId : null,
            'displayOrder' => $command->displayOrder,
            'isRequired' => $command->isRequired,
            'isFilterable' => $command->isFilterable,
            'isSearchable' => $command->isSearchable,
            'isComparable' => $command->isComparable,
            'minimumValue' => $command->minimumValue,
            'maximumValue' => $command->maximumValue,
            'decimalPrecision' => $command->decimalPrecision,
            'validationRulesJson' => $rules->toJson(),
            'isActive' => $command->isActive,
        ];
    }

    private function setActive(ActorContext $actor, SpecificationStatusCommand $command, bool $isActive): SpecificationDefinitionRecord
    {
        return $this->transaction->run(function () use ($actor, $command, $isActive): SpecificationDefinitionRecord {
            $this->require($command->publicId);
            $updated = $this->specifications->setDefinitionActive(
                $command->publicId,
                $isActive,
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'specification-definition',
                entityId: null,
                actionCode: $isActive ? 'activated' : 'deactivated',
                newValues: ['publicId' => $updated->publicId],
            );

            return $updated;
        });
    }

    private function require(string $publicId, bool $includeDeleted = false): SpecificationDefinitionRecord
    {
        $record = $this->specifications->findDefinitionByPublicId($publicId, $includeDeleted);
        if ($record === null) {
            throw SpecificationMasterNotFound::for('definition', $publicId);
        }

        return $record;
    }
}
