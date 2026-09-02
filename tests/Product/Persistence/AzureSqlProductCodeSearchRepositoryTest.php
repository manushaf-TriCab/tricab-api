<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Product\Persistence;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Product\Persistence\AzureSqlProductCodeSearchRepository;
use TriCab\Api\Tests\Doubles\FakeSqlConnection;
use TriCab\Core\Product\Application\DTO\ProductCodeSearchCriteria;

final class AzureSqlProductCodeSearchRepositoryTest extends TestCase
{
    public function test_facet_and_range_search_builds_one_count_and_one_page_query(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['Total' => 2]]);
        $connection->queueFetch([$this->row('BHA-PATN/4C4RE'), $this->row('BHA-PATN/7C4RE', coreCount: 7)]);

        $repository = new AzureSqlProductCodeSearchRepository($connection);
        $result = $repository->search(new ProductCodeSearchCriteria(
            cableType: 'marine-offshore',
            coreCountMin: 4,
            crossSectionMm2Min: 4.0,
            crossSectionMm2Max: 25.0,
            isLszh: true,
        ));

        self::assertCount(2, $connection->calls);
        [$count, $page] = $connection->calls;

        self::assertStringContainsString('COUNT(*)', $count['sql']);
        self::assertStringContainsString('[product].[ProductCodeIndex]', $count['sql']);
        self::assertStringNotContainsString('OUTER APPLY', $count['sql'], 'the count must not pay for catalogue decoration');

        self::assertSame('CABLE-TYPE', $count['params'][':def_cableType']);
        self::assertSame('MARINE-OFFSHORE', $count['params'][':opt_cableType']);
        self::assertSame('IS-LSZH', $count['params'][':def_isLszh']);
        self::assertSame(1, $count['params'][':val_isLszh']);
        self::assertSame(4, $count['params'][':coreCountMin']);
        self::assertSame(4.0, $count['params'][':csaMin']);
        self::assertSame(25.0, $count['params'][':csaMax']);

        self::assertStringContainsString('COALESCE(i.[CrossSectionNominalMm2], i.[CrossSectionMm2]) >= :csaMin', $count['sql']);
        self::assertStringContainsString('OUTER APPLY', $page['sql']);
        self::assertStringContainsString('OFFSET :offset ROWS FETCH NEXT :pageSize ROWS ONLY', $page['sql']);
        self::assertSame(0, $page['params'][':offset']);
        self::assertSame(25, $page['params'][':pageSize']);

        self::assertCount(2, $result->items);
        self::assertSame('BHA-PATN/4C4RE', $result->items[0]->codeValue);
        self::assertSame(4.0, $result->items[0]->crossSectionMm2);
        self::assertSame(2, $result->pagination->totalItems);
    }

    public function test_false_boolean_facet_requires_a_stated_false_not_a_missing_row(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['Total' => 0]]);

        $repository = new AzureSqlProductCodeSearchRepository($connection);
        $repository->search(new ProductCodeSearchCriteria(isArmoured: false));

        $sql = $connection->calls[0]['sql'];
        self::assertStringContainsString('AND EXISTS (', $sql);
        self::assertStringContainsString('[ValueBoolean] = :val_isArmoured', $sql);
        self::assertStringNotContainsString('NOT EXISTS', $sql);
        self::assertSame(0, $connection->calls[0]['params'][':val_isArmoured']);
    }

    public function test_free_text_requires_every_token_to_match_somewhere(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['Total' => 0]]);

        $repository = new AzureSqlProductCodeSearchRepository($connection);
        $repository->search(new ProductCodeSearchCriteria(text: 'marine  lszh'));

        $sql = $connection->calls[0]['sql'];
        $params = $connection->calls[0]['params'];

        self::assertSame('%marine%', $params[':text0Code']);
        self::assertSame('%lszh%', $params[':text1Code']);
        self::assertStringContainsString('STYLE-SYNONYMS', $sql);
        // Two token blocks, AND'ed: one opaque '%marine  lszh%' would match nothing.
        self::assertSame(2, substr_count($sql, 'i.[CodeValue] LIKE :text'));
    }

    public function test_no_results_skips_the_page_query_entirely(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['Total' => 0]]);

        $repository = new AzureSqlProductCodeSearchRepository($connection);
        $result = $repository->search(new ProductCodeSearchCriteria(styleCodes: ['bh']));

        self::assertCount(1, $connection->calls);
        self::assertSame('BH', $connection->calls[0]['params'][':styleCode0']);
        self::assertSame([], $result->items);
        self::assertSame(0, $result->pagination->totalPages);
    }

    public function test_like_wildcards_in_customer_text_are_escaped(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['Total' => 0]]);

        $repository = new AzureSqlProductCodeSearchRepository($connection);
        $repository->search(new ProductCodeSearchCriteria(text: '100%_cu'));

        self::assertSame('%100~%~_cu%', $connection->calls[0]['params'][':text0Code']);
    }

    public function test_suggest_prefix_matches_and_deduplicates(): void
    {
        $connection = new FakeSqlConnection([
            ['CodeValue' => 'BHA-PATN/4C4RE'],
            ['CodeValue' => 'BHA-PATN/4C4RE'],
            ['CodeValue' => 'BHA-PATN/7C4RE'],
        ]);

        $repository = new AzureSqlProductCodeSearchRepository($connection);
        $suggestions = $repository->suggest('BHA-', 5);

        self::assertSame(['BHA-PATN/4C4RE', 'BHA-PATN/7C4RE'], $suggestions);
        self::assertStringContainsString('TOP 5', $connection->calls[0]['sql']);
        self::assertSame('BHA-%', $connection->calls[0]['params'][':prefix']);
    }

    public function test_suggest_ignores_blank_input(): void
    {
        $connection = new FakeSqlConnection();
        $repository = new AzureSqlProductCodeSearchRepository($connection);

        self::assertSame([], $repository->suggest('   '));
        self::assertSame([], $connection->calls);
    }

    public function test_unpublished_scope_drops_the_published_filter_from_the_catalogue_join(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['Total' => 1]]);
        $connection->queueFetch([$this->row('BHA-PATN/4C4RE')]);

        $repository = new AzureSqlProductCodeSearchRepository($connection);
        $repository->search(new ProductCodeSearchCriteria(styleCodes: ['BH'], publishedOnly: false));

        self::assertStringNotContainsString("pst.[StatusCode] = N'published'", $connection->calls[1]['sql']);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $codeValue, int $coreCount = 4): array
    {
        return [
            'CodeValue' => $codeValue,
            'StyleCode' => 'BH',
            'StyleName' => 'Shipboard Fire Res. Braid Power & Control',
            'StyleSuffix' => 'A',
            'JacketTypeCode' => null,
            'CoreColourConfig' => 'PA',
            'BraidArmourScreen' => 'T',
            'ConductorCode' => 'N',
            'CoreCount' => $coreCount,
            'CoreTypeCode' => 'C',
            'CrossSectionRaw' => '4',
            'CrossSectionUnit' => 'mm2',
            'CrossSectionMm2' => '4.000',
            'CrossSectionNominalMm2' => null,
            'SetCores' => 1,
            'JacketColour' => 'RE',
            'StockStatus' => 'MAKE',
            'IsCurrent' => 1,
            'WeightKgPerKm' => '318.500',
            'Description' => 'Shipboard power and control',
            'ProductName' => null,
            'Slug' => null,
            'VariantPublicId' => null,
            'VariantCode' => null,
            'MatchTier' => null,
        ];
    }
}
