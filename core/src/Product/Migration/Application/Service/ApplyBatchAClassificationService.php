<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\UpsertProductCategoryCommand;
use TriCab\Core\Product\Application\Command\UpsertProductSeriesCommand;
use TriCab\Core\Product\Application\Exception\DuplicateProductClassificationCode;
use TriCab\Core\Product\Application\Service\ManageProductClassificationService;
use TriCab\Core\Product\Migration\Classification\CategoryDecisionState;
use TriCab\Core\Product\Migration\Classification\SeriesDecisionState;
use TriCab\Core\Product\Migration\Contract\MigrationBatchRepository;
use TriCab\Core\Product\Migration\Support\MigrationPayload;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

/**
 * Phase 12.7 Batch A controlled target apply.
 * Creates approved Categories + Series and inserts classification mappings only.
 * Never mutates Products, Variants, Catalogue, specs, pricing, media, or legacy DBs.
 */
final class ApplyBatchAClassificationService
{
    private const REQUIRED_CREATE_CODES = [
        'CONDUITS-FITTINGS' => ['name' => 'Conduits & Fittings', 'family' => 'ACCESSORIES'],
        'TECHNO-CONNECTORS' => ['name' => 'Techno Connectors', 'family' => 'PLUG'],
        'TERMINALS' => ['name' => 'Terminals', 'family' => 'ACCESSORIES'],
        'GLANDS' => ['name' => 'Glands', 'family' => 'ACCESSORIES'],
        'LINKS' => ['name' => 'Links', 'family' => 'ACCESSORIES'],
        'PLUG-SOCKET-LEADS' => ['name' => 'Plug-Socket Leads', 'family' => 'ACCESSORIES'],
    ];

    public function __construct(
        private readonly ManageProductClassificationService $classifications,
        private readonly MigrationBatchRepository $batches,
        private readonly ApplicationTransaction $transaction,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $approvedCategories
     * @param list<array<string, mixed>> $approvedSeries
     * @param array<string, mixed> $preflight
     * @return array<string, mixed>
     */
    public function apply(
        ActorContext $actor,
        array $approvedCategories,
        array $approvedSeries,
        array $preflight,
        bool $dryRun = false,
    ): array {
        $this->assertPreflight($preflight);
        $plan = $this->validateApprovedPackage($approvedCategories, $approvedSeries, $preflight);

        if ($dryRun) {
            return [
                'status' => 'DRY_RUN',
                'applied' => false,
                'plan' => $plan,
                'actualWrites' => $this->emptyWrites(),
            ];
        }

        return $this->transaction->run(function () use ($actor, $approvedCategories, $approvedSeries, $preflight, $plan): array {
            $familyByCode = $this->indexFamilies($preflight['families'] ?? []);
            $categoryByCode = $this->indexByCode($preflight['categories'] ?? []);
            $seriesByCode = $this->indexByCode($preflight['series'] ?? []);

            $writes = $this->emptyWrites();
            $createdCategories = [];
            $createdSeries = [];
            $categoryMappings = [];
            $seriesMappings = [];

            // 1) Create unique Categories (idempotent if code already exists).
            foreach ($plan['uniqueCategoryCreates'] as $code => $meta) {
                $familyCode = strtoupper((string) $meta['family']);
                if (!isset($familyByCode[$familyCode])) {
                    throw new \RuntimeException('Family missing for Category create: ' . $code . ' → ' . $familyCode);
                }
                if (isset($categoryByCode[$code])) {
                    $createdCategories[$code] = [
                        'code' => $code,
                        'name' => $categoryByCode[$code]['name'],
                        'family' => $familyCode,
                        'publicId' => $categoryByCode[$code]['publicId'],
                        'created' => false,
                        'reusedExisting' => true,
                    ];
                    continue;
                }
                try {
                    $record = $this->classifications->createCategory($actor, new UpsertProductCategoryCommand(
                        familyPublicId: (string) $familyByCode[$familyCode]['publicId'],
                        name: (string) $meta['name'],
                        code: $code,
                        description: 'Batch A.3 operator-approved Category',
                        displayOrder: 100 + count($createdCategories),
                        isActive: true,
                    ));
                } catch (DuplicateProductClassificationCode $e) {
                    // Race / prior partial — re-list via preflight refresh not available; fail loud.
                    throw new \RuntimeException('Category code collision during create: ' . $code, 0, $e);
                }
                $writes['categoriesCreated']++;
                $categoryByCode[$code] = [
                    'code' => $code,
                    'name' => $record->name,
                    'publicId' => $record->publicId,
                    'familyCode' => $familyCode,
                ];
                $createdCategories[$code] = [
                    'code' => $code,
                    'name' => $record->name,
                    'family' => $familyCode,
                    'publicId' => $record->publicId,
                    'created' => true,
                    'reusedExisting' => false,
                ];
            }

            // 2) Category mapping rows.
            foreach ($approvedCategories as $row) {
                $decision = (string) ($row['decision'] ?? '');
                if (!in_array($decision, [CategoryDecisionState::APPROVED_MAP_EXISTING, CategoryDecisionState::APPROVED_CREATE], true)) {
                    continue;
                }
                $targetCode = $decision === CategoryDecisionState::APPROVED_MAP_EXISTING
                    ? strtoupper((string) ($row['targetCategoryCode'] ?? ''))
                    : strtoupper((string) ($row['proposedCategoryCode'] ?? ''));
                if ($targetCode === '' || !isset($categoryByCode[$targetCode])) {
                    throw new \RuntimeException('Category mapping target unresolved: ' . $targetCode);
                }
                $legacyValue = $this->legacyCategoryKey($row);
                $familyCode = strtoupper((string) (
                    $row['proposedFamily']
                    ?? $categoryByCode[$targetCode]['familyCode']
                    ?? ''
                ));
                $familyPublicId = $familyCode !== '' && isset($familyByCode[$familyCode])
                    ? (string) $familyByCode[$familyCode]['publicId']
                    : null;
                $result = $this->upsertMappingIdempotent($actor, [
                    'sourceSystem' => 'wordpress',
                    'legacyClassificationValue' => $legacyValue,
                    'legacyTaxonomy' => 'category',
                    'targetFamilyPublicId' => $familyPublicId,
                    'targetCategoryPublicId' => (string) $categoryByCode[$targetCode]['publicId'],
                    'targetSeriesPublicId' => null,
                    'statusCode' => 'mapped',
                    'notes' => json_encode([
                        'batch' => '12.7-batch-a',
                        'decision' => $decision,
                        'targetCategoryCode' => $targetCode,
                        'sourceTermId' => $row['sourceTermId'] ?? $row['termId'] ?? null,
                        'decodedName' => $row['decodedName'] ?? null,
                    ], JSON_UNESCAPED_SLASHES),
                ]);
                if ($result['inserted']) {
                    $writes['categoryMappingsInserted']++;
                } elseif ($result['updated']) {
                    $writes['mappingsUpdated']++;
                } else {
                    $writes['categoryMappingsSkippedExisting']++;
                }
                $categoryMappings[] = $result['row'];
            }

            // 3) Create Series under resolved Categories.
            foreach ($approvedSeries as $row) {
                if (($row['decision'] ?? '') !== SeriesDecisionState::APPROVED_CREATE) {
                    continue;
                }
                $code = strtoupper((string) ($row['proposedCode'] ?? $row['styleCode'] ?? ''));
                $name = trim((string) ($row['proposedName'] ?? $row['productTitle'] ?? $code));
                $categoryCode = strtoupper((string) ($row['category'] ?? $row['categoryCode'] ?? ''));
                if ($code === '' || $categoryCode === '' || !isset($categoryByCode[$categoryCode])) {
                    throw new \RuntimeException('Series parent Category unresolved for ' . $code . ' → ' . $categoryCode);
                }
                if (isset($seriesByCode[$code])) {
                    $createdSeries[$code] = [
                        'code' => $code,
                        'name' => $seriesByCode[$code]['name'],
                        'category' => $categoryCode,
                        'publicId' => $seriesByCode[$code]['publicId'],
                        'created' => false,
                        'reusedExisting' => true,
                    ];
                    continue;
                }
                $record = $this->classifications->createSeries($actor, new UpsertProductSeriesCommand(
                    categoryPublicId: (string) $categoryByCode[$categoryCode]['publicId'],
                    name: $name !== '' ? $name : $code,
                    code: $code,
                    description: 'Batch A.3 operator-approved Series',
                    displayOrder: 100 + count($createdSeries),
                    isActive: true,
                ));
                $writes['seriesCreated']++;
                $seriesByCode[$code] = [
                    'code' => $code,
                    'name' => $record->name,
                    'publicId' => $record->publicId,
                    'categoryCode' => $categoryCode,
                ];
                $createdSeries[$code] = [
                    'code' => $code,
                    'name' => $record->name,
                    'category' => $categoryCode,
                    'publicId' => $record->publicId,
                    'created' => true,
                    'reusedExisting' => false,
                ];
            }

            // 4) Series mappings.
            foreach ($approvedSeries as $row) {
                $seriesDecision = (string) ($row['decision'] ?? '');
                if ($seriesDecision !== SeriesDecisionState::APPROVED_CREATE
                    && $seriesDecision !== SeriesDecisionState::APPROVED_MAP_EXISTING
                ) {
                    continue;
                }
                $code = strtoupper((string) ($row['proposedCode'] ?? $row['styleCode'] ?? ''));
                $categoryCode = strtoupper((string) ($row['category'] ?? $row['categoryCode'] ?? ''));
                if (!isset($seriesByCode[$code]) || !isset($categoryByCode[$categoryCode])) {
                    throw new \RuntimeException('Series mapping unresolved for ' . $code);
                }
                $familyCode = strtoupper((string) ($row['family'] ?? ''));
                $familyPublicId = $familyCode !== '' && isset($familyByCode[$familyCode])
                    ? (string) $familyByCode[$familyCode]['publicId']
                    : null;
                $result = $this->upsertMappingIdempotent($actor, [
                    'sourceSystem' => 'wordpress',
                    'legacyClassificationValue' => 'style:' . $code,
                    'legacyTaxonomy' => 'style_code',
                    'targetFamilyPublicId' => $familyPublicId,
                    'targetCategoryPublicId' => (string) $categoryByCode[$categoryCode]['publicId'],
                    'targetSeriesPublicId' => (string) $seriesByCode[$code]['publicId'],
                    'statusCode' => 'mapped',
                    'notes' => json_encode([
                        'batch' => '12.7-batch-a',
                        'decision' => $row['decision'] ?? null,
                        'styleCode' => $row['styleCode'] ?? $code,
                        'productTitle' => $row['productTitle'] ?? null,
                    ], JSON_UNESCAPED_SLASHES),
                ]);
                if ($result['inserted']) {
                    $writes['seriesMappingsInserted']++;
                } elseif ($result['updated']) {
                    $writes['mappingsUpdated']++;
                } else {
                    $writes['seriesMappingsSkippedExisting']++;
                }
                $seriesMappings[] = $result['row'];
            }

            return [
                'status' => 'COMPLETED',
                'applied' => true,
                'plan' => $plan,
                'actualWrites' => $writes,
                'createdCategories' => array_values($createdCategories),
                'createdSeries' => array_values($createdSeries),
                'categoryMappings' => $categoryMappings,
                'seriesMappings' => $seriesMappings,
                'productMutations' => 0,
                'variantMutations' => 0,
                'catalogueMutations' => 0,
                'legacyWrites' => 0,
            ];
        });
    }

    /**
     * @param array<string, mixed> $preflight
     */
    private function assertPreflight(array $preflight): void
    {
        $server = (string) ($preflight['server'] ?? '');
        $database = (string) ($preflight['database'] ?? '');
        if (!str_contains(strtolower($server), 'tricab-mel')) {
            throw new \RuntimeException('Refusing apply: server is not tricab-mel (' . $server . ')');
        }
        if (strtolower($database) !== 'tc') {
            throw new \RuntimeException('Refusing apply: database is not tc (' . $database . ')');
        }
        if (($preflight['environmentLabel'] ?? '') !== 'NON-PRODUCTION') {
            throw new \RuntimeException('Refusing apply: environment is not NON-PRODUCTION');
        }
        $familyRows = is_array($preflight['families'] ?? null) ? $preflight['families'] : [];
        if (count($familyRows) < 3) {
            throw new \RuntimeException('Refusing apply: expected at least 3 Families');
        }
    }

    /**
     * @param list<array<string, mixed>> $approvedCategories
     * @param list<array<string, mixed>> $approvedSeries
     * @param array<string, mixed> $preflight
     * @return array<string, mixed>
     */
    private function validateApprovedPackage(array $approvedCategories, array $approvedSeries, array $preflight): array
    {
        $mapExisting = 0;
        $createRows = 0;
        $uniqueCreates = [];
        foreach ($approvedCategories as $row) {
            $d = (string) ($row['decision'] ?? '');
            if ($d === CategoryDecisionState::APPROVED_MAP_EXISTING) {
                ++$mapExisting;
            }
            if ($d === CategoryDecisionState::APPROVED_CREATE) {
                ++$createRows;
                $code = strtoupper((string) ($row['proposedCategoryCode'] ?? ''));
                $family = strtoupper((string) ($row['proposedFamily'] ?? ''));
                $name = (string) ($row['proposedCategoryName'] ?? $row['decodedName'] ?? $code);
                if ($code === '' || $family === '') {
                    throw new \RuntimeException('Invalid APPROVED_CREATE Category row');
                }
                if (isset(self::REQUIRED_CREATE_CODES[$code])) {
                    $expectedFamily = self::REQUIRED_CREATE_CODES[$code]['family'];
                    if ($family !== $expectedFamily) {
                        throw new \RuntimeException("Family mismatch for {$code}: got {$family}, expected {$expectedFamily}");
                    }
                }
                $uniqueCreates[$code] = ['name' => $name, 'family' => $family];
            }
        }
        foreach (self::REQUIRED_CREATE_CODES as $code => $meta) {
            if (!isset($uniqueCreates[$code])) {
                throw new \RuntimeException('Missing required Category create: ' . $code);
            }
            if ($uniqueCreates[$code]['family'] !== $meta['family']) {
                throw new \RuntimeException('Required family mismatch for ' . $code);
            }
        }
        if (count($uniqueCreates) !== 6) {
            throw new \RuntimeException('Expected exactly 6 unique Category creates, got ' . count($uniqueCreates));
        }
        if ($mapExisting !== 27) {
            throw new \RuntimeException('Expected 27 APPROVED_MAP_EXISTING Category mappings, got ' . $mapExisting);
        }
        if ($createRows !== 7) {
            throw new \RuntimeException('Expected 7 APPROVED_CREATE Category source rows, got ' . $createRows);
        }

        $seriesCreates = 0;
        $seriesCodes = [];
        foreach ($approvedSeries as $row) {
            if (($row['decision'] ?? '') !== SeriesDecisionState::APPROVED_CREATE) {
                continue;
            }
            ++$seriesCreates;
            $code = strtoupper((string) ($row['proposedCode'] ?? $row['styleCode'] ?? ''));
            $cat = strtoupper((string) ($row['category'] ?? $row['categoryCode'] ?? ''));
            if ($code === '' || $cat === '') {
                throw new \RuntimeException('Series missing code/category');
            }
            if (str_contains($code, ',') || preg_match('/^\d+$/', $code) === 1) {
                throw new \RuntimeException('Series code invalid/composite/numeric: ' . $code);
            }
            if (isset($seriesCodes[$code])) {
                throw new \RuntimeException('Duplicate Series code in approved set: ' . $code);
            }
            // Existing target Series with the same code is allowed for idempotent re-apply (reuse).
            $seriesCodes[$code] = $cat;
        }
        if ($seriesCreates !== 95) {
            throw new \RuntimeException('Expected 95 APPROVED_CREATE Series, got ' . $seriesCreates);
        }

        return [
            'mapExistingCategories' => $mapExisting,
            'approvedCreateCategorySourceRows' => $createRows,
            'uniqueCategoryCreates' => $uniqueCreates,
            'seriesCreates' => $seriesCreates,
            'categoryMappingRows' => $mapExisting + $createRows,
            'seriesMappingRows' => $seriesCreates,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{inserted: bool, updated: bool, skipped: bool, row: array<string, mixed>}
     */
    private function upsertMappingIdempotent(ActorContext $actor, array $data): array
    {
        $sourceSystem = MigrationPayload::string($data['sourceSystem'] ?? 'wordpress');
        $legacyValue = MigrationPayload::string($data['legacyClassificationValue'] ?? '');
        $taxonomy = MigrationPayload::stringOrNull($data['legacyTaxonomy'] ?? null);
        $existingPublicId = null;
        $existingRow = null;
        foreach ($this->batches->listClassificationMappings() as $row) {
            if (($row['sourceSystem'] ?? '') === $sourceSystem
                && ($row['legacyClassificationValue'] ?? $row['legacyValue'] ?? '') === $legacyValue
                && (($row['legacyTaxonomy'] ?? null) === $taxonomy)
            ) {
                $existingPublicId = MigrationPayload::stringOrNull($row['publicId'] ?? null);
                $existingRow = $row;
                break;
            }
        }

        $sameTarget = $existingRow !== null
            && ($existingRow['targetCategoryPublicId'] ?? null) === ($data['targetCategoryPublicId'] ?? null)
            && ($existingRow['targetSeriesPublicId'] ?? null) === ($data['targetSeriesPublicId'] ?? null)
            && ($existingRow['targetFamilyPublicId'] ?? null) === ($data['targetFamilyPublicId'] ?? null)
            && ($existingRow['statusCode'] ?? '') === ($data['statusCode'] ?? 'mapped');

        if ($sameTarget && $existingPublicId !== null) {
            return ['inserted' => false, 'updated' => false, 'skipped' => true, 'row' => $existingRow];
        }

        $data['mappedByUserId'] = $actor->userId;
        $row = $this->batches->upsertClassificationMapping($existingPublicId ?? 'new', $data);

        return [
            'inserted' => $existingPublicId === null,
            'updated' => $existingPublicId !== null,
            'skipped' => false,
            'row' => $row,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function legacyCategoryKey(array $row): string
    {
        $termId = (int) ($row['sourceTermId'] ?? $row['termId'] ?? 0);
        if ($termId > 0) {
            return 'term:' . $termId;
        }
        $name = MigrationPayload::string($row['decodedName'] ?? $row['rawName'] ?? $row['normalizedName'] ?? '');

        return 'category:' . $name;
    }

    /**
     * @param mixed $rows
     * @return array<string, array<string, mixed>>
     */
    private function indexFamilies(mixed $rows): array
    {
        $out = [];
        if (!\is_array($rows)) {
            return $out;
        }
        /** @var list<mixed> $list */
        $list = array_values($rows);
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = strtoupper(MigrationPayload::string($row['code'] ?? $row['FamilyCode'] ?? ''));
            if ($code === '') {
                continue;
            }
            $out[$code] = [
                'code' => $code,
                'name' => MigrationPayload::string($row['name'] ?? $row['FamilyName'] ?? ''),
                'publicId' => MigrationPayload::string($row['publicId'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param mixed $rows
     * @return array<string, array<string, mixed>>
     */
    private function indexByCode(mixed $rows): array
    {
        $out = [];
        if (!is_array($rows)) {
            return $out;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = strtoupper(MigrationPayload::string($row['code'] ?? $row['CategoryCode'] ?? $row['SeriesCode'] ?? ''));
            if ($code === '') {
                continue;
            }
            $out[$code] = [
                'code' => $code,
                'name' => MigrationPayload::string($row['name'] ?? $row['CategoryName'] ?? $row['SeriesName'] ?? ''),
                'publicId' => MigrationPayload::string($row['publicId'] ?? ''),
                'familyCode' => MigrationPayload::stringOrNull($row['familyCode'] ?? $row['family'] ?? null),
                'categoryCode' => MigrationPayload::stringOrNull($row['categoryCode'] ?? $row['category'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, int>
     */
    private function emptyWrites(): array
    {
        return [
            'categoriesCreated' => 0,
            'seriesCreated' => 0,
            'categoryMappingsInserted' => 0,
            'seriesMappingsInserted' => 0,
            'mappingsUpdated' => 0,
            'categoryMappingsSkippedExisting' => 0,
            'seriesMappingsSkippedExisting' => 0,
        ];
    }
}
