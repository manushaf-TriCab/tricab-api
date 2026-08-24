<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

use TriCab\Core\Product\Migration\Support\MigrationPayload;

/**
 * Phase 12.7 scoped master-data apply helper (target Azure only).
 * Never writes Products/Variants/prices/media. Never touches legacy MySQL.
 *
 * @phpstan-type OpReport array{
 *   scope: string,
 *   action: string,
 *   object: string,
 *   detail?: string
 * }
 */
final class ScopedMasterDataApplyPlanner
{
    public const SCOPE_SPECIFICATION_UNITS = 'specification-units';
    public const SCOPE_ACCESSORIES_FAMILY = 'accessories-family';
    public const SCOPE_MIGRATION_SPEC_FIELD_SCHEMA = 'migration-spec-field-schema';

    /** @return list<string> */
    public static function approvedScopes(): array
    {
        return [
            self::SCOPE_SPECIFICATION_UNITS,
            self::SCOPE_ACCESSORIES_FAMILY,
            self::SCOPE_MIGRATION_SPEC_FIELD_SCHEMA,
        ];
    }

    /**
     * @param list<string> $requested
     * @return list<string>
     */
    public function normalizeScopes(array $requested): array
    {
        $out = [];
        foreach ($requested as $scope) {
            $s = strtolower(trim($scope));
            if ($s === '') {
                continue;
            }
            if (!in_array($s, self::approvedScopes(), true)) {
                throw new \InvalidArgumentException('Unsupported or unapproved scope: ' . $scope);
            }
            $out[] = $s;
        }
        $out = array_values(array_unique($out));
        if ($out === []) {
            throw new \InvalidArgumentException('At least one --scope value is required.');
        }

        return $out;
    }

    /**
     * @return list<array{code: string, name: string, symbol: string, meaning: string, displayOrder: int}>
     */
    public function approvedUnits(): array
    {
        return [
            ['code' => 'M', 'name' => 'Metre', 'symbol' => 'm', 'meaning' => 'metre (pricing default + length)', 'displayOrder' => 10],
            ['code' => 'EA', 'name' => 'Each', 'symbol' => 'ea', 'meaning' => 'each (pricing)', 'displayOrder' => 20],
            ['code' => 'FT', 'name' => 'Foot', 'symbol' => 'ft', 'meaning' => 'foot (pricing US display)', 'displayOrder' => 30],
            ['code' => 'PK', 'name' => 'Pack', 'symbol' => 'pk', 'meaning' => 'pack (pricing)', 'displayOrder' => 40],
            ['code' => 'MM', 'name' => 'Millimetre', 'symbol' => 'mm', 'meaning' => 'millimetre (spec length)', 'displayOrder' => 50],
            ['code' => 'MM2', 'name' => 'Square millimetre', 'symbol' => 'mm²', 'meaning' => 'square millimetre (cross-section)', 'displayOrder' => 60],
            ['code' => 'KG', 'name' => 'Kilogram', 'symbol' => 'kg', 'meaning' => 'kilogram', 'displayOrder' => 70],
            ['code' => 'KG_PER_KM', 'name' => 'Kilogram per km', 'symbol' => 'kg/km', 'meaning' => 'kilogram per kilometre', 'displayOrder' => 80],
            ['code' => 'A', 'name' => 'Ampere', 'symbol' => 'A', 'meaning' => 'ampere', 'displayOrder' => 90],
            ['code' => 'V', 'name' => 'Volt', 'symbol' => 'V', 'meaning' => 'volt', 'displayOrder' => 100],
            ['code' => 'C', 'name' => 'Degree Celsius', 'symbol' => '°C', 'meaning' => 'degree Celsius', 'displayOrder' => 110],
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param list<string> $scopes
     * @return array<string, mixed>
     */
    public function plan(array $snapshot, array $scopes): array
    {
        $ops = [];
        $counts = [
            'CREATE_TABLE' => 0,
            'ALTER_TABLE' => 0,
            'INSERT' => 0,
            'UPDATE' => 0,
            'SKIP' => 0,
            'CONFLICT' => 0,
            'DROP_CONSTRAINT' => 0,
            'CREATE_INDEX' => 0,
        ];

        if (in_array(self::SCOPE_SPECIFICATION_UNITS, $scopes, true)) {
            $unitsPresent = (bool) ($snapshot['specificationUnitsPresent'] ?? false);
            if (!$unitsPresent) {
                $ops[] = [
                    'scope' => self::SCOPE_SPECIFICATION_UNITS,
                    'action' => 'CREATE_TABLE',
                    'object' => 'product.SpecificationUnits',
                    'detail' => 'Phase 11-compatible SpecificationUnits master',
                ];
                ++$counts['CREATE_TABLE'];
            } else {
                $ops[] = [
                    'scope' => self::SCOPE_SPECIFICATION_UNITS,
                    'action' => 'SKIP',
                    'object' => 'product.SpecificationUnits',
                    'detail' => 'already present',
                ];
                ++$counts['SKIP'];
            }

            $existingUnits = [];
            foreach (($snapshot['units'] ?? []) as $row) {
                if (is_array($row)) {
                    $code = strtoupper(MigrationPayload::string($row['code'] ?? ''));
                    if ($code !== '') {
                        $existingUnits[$code] = $row;
                    }
                }
            }
            foreach ($this->approvedUnits() as $unit) {
                if (isset($existingUnits[$unit['code']])) {
                    $ops[] = [
                        'scope' => self::SCOPE_SPECIFICATION_UNITS,
                        'action' => 'SKIP',
                        'object' => 'SpecificationUnit:' . $unit['code'],
                        'detail' => 'already exists',
                        'unit' => $unit,
                        'alreadyExists' => true,
                    ];
                    ++$counts['SKIP'];
                } else {
                    $ops[] = [
                        'scope' => self::SCOPE_SPECIFICATION_UNITS,
                        'action' => 'INSERT',
                        'object' => 'SpecificationUnit:' . $unit['code'],
                        'detail' => $unit['name'] . ' / ' . $unit['symbol'],
                        'unit' => $unit,
                        'alreadyExists' => false,
                    ];
                    ++$counts['INSERT'];
                }
            }
        }

        if (in_array(self::SCOPE_ACCESSORIES_FAMILY, $scopes, true)) {
            $families = [];
            foreach (($snapshot['families'] ?? []) as $row) {
                if (is_array($row)) {
                    $code = strtoupper(MigrationPayload::string($row['code'] ?? ''));
                    if ($code !== '') {
                        $families[$code] = $row;
                    }
                }
            }
            if (isset($families['ACCESSORIES'])) {
                $ops[] = [
                    'scope' => self::SCOPE_ACCESSORIES_FAMILY,
                    'action' => 'SKIP',
                    'object' => 'ProductFamily:ACCESSORIES',
                    'detail' => 'MAP_EXISTING',
                    'family' => [
                        'code' => 'ACCESSORIES',
                        'name' => MigrationPayload::string($families['ACCESSORIES']['name'] ?? 'Accessories'),
                        'conflict' => 'same code exists',
                    ],
                ];
                ++$counts['SKIP'];
            } else {
                $ops[] = [
                    'scope' => self::SCOPE_ACCESSORIES_FAMILY,
                    'action' => 'INSERT',
                    'object' => 'ProductFamily:ACCESSORIES',
                    'detail' => 'CREATE Accessories family; PLUG kept separate',
                    'family' => [
                        'code' => 'ACCESSORIES',
                        'name' => 'Accessories',
                        'displayOrder' => 30,
                        'isActive' => true,
                        'existingConflict' => false,
                        'productsAffectedLater' => 216,
                    ],
                ];
                ++$counts['INSERT'];
            }
        }

        if (in_array(self::SCOPE_MIGRATION_SPEC_FIELD_SCHEMA, $scopes, true)) {
            $mappingTable = (bool) ($snapshot['specificationFieldMappingsPresent'] ?? false);
            $styleCol = (bool) ($snapshot['legacyStyleCodePresent'] ?? false);
            $oldUq = (bool) ($snapshot['oldSourceUniquePresent'] ?? false);
            $newUq = (bool) ($snapshot['styleUniqueIndexPresent'] ?? false);

            if (!$mappingTable) {
                $ops[] = [
                    'scope' => self::SCOPE_MIGRATION_SPEC_FIELD_SCHEMA,
                    'action' => 'CONFLICT',
                    'object' => 'migration.SpecificationFieldMappings',
                    'detail' => 'mapping table missing — apply create-product-migration-schema.sql first',
                ];
                ++$counts['CONFLICT'];
            } else {
                if (!$styleCol) {
                    $ops[] = [
                        'scope' => self::SCOPE_MIGRATION_SPEC_FIELD_SCHEMA,
                        'action' => 'ALTER_TABLE',
                        'object' => 'migration.SpecificationFieldMappings.LegacyStyleCode',
                        'detail' => 'ADD NVARCHAR(50) NULL — no mapping data insert',
                    ];
                    ++$counts['ALTER_TABLE'];
                } else {
                    $ops[] = [
                        'scope' => self::SCOPE_MIGRATION_SPEC_FIELD_SCHEMA,
                        'action' => 'SKIP',
                        'object' => 'migration.SpecificationFieldMappings.LegacyStyleCode',
                        'detail' => 'already present',
                    ];
                    ++$counts['SKIP'];
                }
                if ($oldUq) {
                    $ops[] = [
                        'scope' => self::SCOPE_MIGRATION_SPEC_FIELD_SCHEMA,
                        'action' => 'DROP_CONSTRAINT',
                        'object' => 'UQ_migration_SpecificationFieldMappings_Source',
                        'detail' => 'replace unique key to include LegacyStyleCode (no row delete)',
                    ];
                    ++$counts['DROP_CONSTRAINT'];
                }
                if (!$newUq) {
                    $ops[] = [
                        'scope' => self::SCOPE_MIGRATION_SPEC_FIELD_SCHEMA,
                        'action' => 'CREATE_INDEX',
                        'object' => 'UQ_migration_SpecificationFieldMappings_SourceStyle',
                        'detail' => 'unique (SourceSystem, SourceTable, SourceField, LegacyStyleCode)',
                    ];
                    ++$counts['CREATE_INDEX'];
                } else {
                    $ops[] = [
                        'scope' => self::SCOPE_MIGRATION_SPEC_FIELD_SCHEMA,
                        'action' => 'SKIP',
                        'object' => 'UQ_migration_SpecificationFieldMappings_SourceStyle',
                        'detail' => 'already present',
                    ];
                    ++$counts['SKIP'];
                }
            }
        }

        return [
            'scopes' => $scopes,
            'operations' => $ops,
            'counts' => $counts,
            'forbiddenGuarantees' => [
                'dvMappingRecordInserts' => 0,
                'categoryCreates' => 0,
                'seriesCreates' => 0,
                'definitionCreates' => 0,
                'optionAliasCreates' => 0,
                'productWrites' => 0,
                'variantWrites' => 0,
                'priceWrites' => 0,
                'mediaWrites' => 0,
                'legacyWrites' => 0,
            ],
        ];
    }
}
