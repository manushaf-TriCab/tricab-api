<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Migration\BatchA;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use TriCab\Core\Product\Application\Service\ManageProductClassificationService;
use TriCab\Core\Product\Migration\Application\Service\ApplyBatchAClassificationService;
use TriCab\Core\Product\Migration\Classification\CategoryDecisionState;
use TriCab\Core\Product\Migration\Classification\SeriesDecisionState;
use TriCab\Core\Product\Migration\Contract\MigrationBatchRepository;
use TriCab\Core\Tests\Product\Doubles\PassthroughApplicationTransaction;

final class ApplyBatchAClassificationServiceTest extends TestCase
{
    public function testRejectsWrongTarget(): void
    {
        $svc = $this->serviceWithoutMocks();
        $this->expectException(\RuntimeException::class);
        $this->invokePrivate($svc, 'assertPreflight', [[
            'server' => 'wrong-server',
            'database' => 'tc',
            'environmentLabel' => 'NON-PRODUCTION',
            'families' => [['code' => 'CABLE'], ['code' => 'PLUG'], ['code' => 'ACCESSORIES']],
        ]]);
    }

    public function testValidatesPackageCounts(): void
    {
        $svc = $this->serviceWithoutMocks();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Expected 27 APPROVED_MAP_EXISTING');
        $cats = $this->minimalApprovedCategories();
        $cats = array_values(array_filter(
            $cats,
            static fn (array $r): bool => ($r['decision'] ?? '') !== CategoryDecisionState::APPROVED_MAP_EXISTING
                || ((int) ($r['sourceTermId'] ?? 0) % 2 === 0),
        ));
        // Keep creates; drop some MAP_EXISTING so count != 27.
        $this->invokePrivate($svc, 'validateApprovedPackage', [
            $cats,
            $this->minimalApprovedSeries(),
            $this->okPreflight(),
        ]);
    }

    public function testTechnoMustBePlug(): void
    {
        $svc = $this->serviceWithoutMocks();
        $this->expectException(\RuntimeException::class);
        $this->invokePrivate($svc, 'validateApprovedPackage', [
            $this->minimalApprovedCategories(technoFamily: 'ACCESSORIES'),
            $this->minimalApprovedSeries(),
            $this->okPreflight(),
        ]);
    }

    public function testValidPackagePasses(): void
    {
        $svc = $this->serviceWithoutMocks();
        $plan = $this->invokePrivate($svc, 'validateApprovedPackage', [
            $this->minimalApprovedCategories(),
            $this->minimalApprovedSeries(),
            $this->okPreflight(),
        ]);
        self::assertSame(27, $plan['mapExistingCategories']);
        self::assertSame(7, $plan['approvedCreateCategorySourceRows']);
        self::assertCount(6, $plan['uniqueCategoryCreates']);
        self::assertSame(95, $plan['seriesCreates']);
        self::assertSame('PLUG', $plan['uniqueCategoryCreates']['TECHNO-CONNECTORS']['family']);
    }

    /**
     * @return array<string, mixed>
     */
    private function okPreflight(): array
    {
        return [
            'server' => 'tricab-mel',
            'database' => 'tc',
            'environmentLabel' => 'NON-PRODUCTION',
            'families' => [
                ['code' => 'CABLE', 'publicId' => 'fam-cable', 'name' => 'Cable'],
                ['code' => 'PLUG', 'publicId' => 'fam-plug', 'name' => 'Plug'],
                ['code' => 'ACCESSORIES', 'publicId' => 'fam-acc', 'name' => 'Accessories'],
            ],
            'categories' => [
                ['code' => 'CTRL-MULTI', 'publicId' => 'cat-ctrl', 'name' => 'Power and Control', 'familyCode' => 'CABLE'],
            ],
            'series' => [],
            'products' => 118,
            'variants' => 4178,
            'catalogueNodes' => 97,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function minimalApprovedCategories(string $technoFamily = 'PLUG'): array
    {
        $creates = [
            ['CONDUITS-FITTINGS', 'Conduits & Fittings', 'ACCESSORIES'],
            ['TECHNO-CONNECTORS', 'Techno Connectors', $technoFamily],
            ['TERMINALS', 'Terminals', 'ACCESSORIES'],
            ['GLANDS', 'Glands', 'ACCESSORIES'],
            ['LINKS', 'Links', 'ACCESSORIES'],
            ['PLUG-SOCKET-LEADS', 'Plug-Socket Leads', 'ACCESSORIES'],
        ];
        $rows = [];
        for ($i = 0; $i < 27; ++$i) {
            $rows[] = [
                'decision' => CategoryDecisionState::APPROVED_MAP_EXISTING,
                'sourceTermId' => 1000 + $i,
                'targetCategoryCode' => 'CTRL-MULTI',
                'decodedName' => 'map-' . $i,
            ];
        }
        foreach ($creates as [$code, $name, $family]) {
            $rows[] = [
                'decision' => CategoryDecisionState::APPROVED_CREATE,
                'sourceTermId' => crc32($code),
                'proposedCategoryCode' => $code,
                'proposedCategoryName' => $name,
                'proposedFamily' => $family,
                'decodedName' => $name,
            ];
        }
        $rows[] = [
            'decision' => CategoryDecisionState::APPROVED_CREATE,
            'sourceTermId' => 542,
            'proposedCategoryCode' => 'GLANDS',
            'proposedCategoryName' => 'Glands',
            'proposedFamily' => 'ACCESSORIES',
            'decodedName' => 'Glands',
        ];

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function minimalApprovedSeries(): array
    {
        $rows = [];
        for ($i = 0; $i < 95; ++$i) {
            $code = 'S' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $rows[] = [
                'decision' => SeriesDecisionState::APPROVED_CREATE,
                'styleCode' => $code,
                'proposedCode' => $code,
                'proposedName' => 'Series ' . $code,
                'family' => 'CABLE',
                'category' => 'CTRL-MULTI',
                'categoryCode' => 'CTRL-MULTI',
            ];
        }

        return $rows;
    }

    private function serviceWithoutMocks(): ApplyBatchAClassificationService
    {
        // Final collaborators cannot be PHPUnit-doubled; validation tests do not invoke them.
        $classifications = (new \ReflectionClass(ManageProductClassificationService::class))->newInstanceWithoutConstructor();
        $batches = $this->createMock(MigrationBatchRepository::class);

        return new ApplyBatchAClassificationService(
            $classifications,
            $batches,
            new PassthroughApplicationTransaction(),
        );
    }

    /**
     * @param list<mixed> $args
     */
    private function invokePrivate(object $svc, string $method, array $args): mixed
    {
        $ref = new ReflectionMethod($svc, $method);

        return $ref->invokeArgs($svc, $args);
    }
}
