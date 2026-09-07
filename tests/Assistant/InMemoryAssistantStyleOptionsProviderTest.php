<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Assistant;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Assistant\Infrastructure\InMemoryAssistantStyleOptionsProvider;
use TriCab\Core\Product\Application\DTO\ProductStyleInheritanceConfig;
use TriCab\Core\Product\Application\DTO\ProductStyleRecord;
use TriCab\Core\Product\Contract\ProductStyleRepository;

final class InMemoryAssistantStyleOptionsProviderTest extends TestCase
{
    public function test_a_page_reports_the_total_so_truncation_is_never_silent(): void
    {
        // The defect this replaced returned a bare list: 30-of-145 came back
        // indistinguishable from 30-of-30, so a partial view read as the whole
        // catalogue and the agent concluded a style category did not exist.
        $provider = $this->provider(['AD', 'AR', 'CC', 'KL', 'LP', 'LS', 'XL']);

        $first = $provider->search([], null, null, 1, 3);
        self::assertCount(3, $first['items']);
        self::assertSame(7, $first['totalItems']);
        self::assertSame(1, $first['page']);
    }

    public function test_paging_reaches_styles_past_the_first_page(): void
    {
        // LP and LS are alphabetically late, which is exactly why the old
        // alphabetical cap made them unreachable.
        $provider = $this->provider(['AD', 'AR', 'CC', 'KL', 'LP', 'LS', 'XL']);

        $codes = array_column($provider->search([], null, null, 2, 3)['items'], 'styleCode');

        self::assertSame(['KL', 'LP', 'LS'], $codes);
    }

    public function test_every_search_word_must_match(): void
    {
        $provider = $this->provider([
            'LP' => 'Flexible Rubber Building Wire',
            'LS' => 'Flexible LSZH Switchboard/ Panel Wire',
        ]);

        self::assertCount(2, $provider->search([], 'flexible', null, 1, 20)['items']);
        self::assertSame(
            ['LS'],
            array_column($provider->search([], 'flexible lszh', null, 1, 20)['items'], 'styleCode'),
        );
        self::assertSame([], $provider->search([], 'flexible lszh rubber', null, 1, 20)['items']);
    }

    public function test_a_page_size_beyond_the_cap_is_clamped_not_honoured(): void
    {
        $result = $this->provider(['AD'])->search([], null, null, 1, 5_000);

        self::assertSame(50, $result['pageSize']);
    }

    /** @param array<int|string, string> $styles */
    private function provider(array $styles): InMemoryAssistantStyleOptionsProvider
    {
        $records = [];
        foreach ($styles as $code => $name) {
            $styleCode = is_string($code) ? $code : $name;
            $displayName = is_string($code) ? $name : $name . ' Style';
            $records[] = new ProductStyleRecord(
                publicId: 'style-' . strtolower($styleCode),
                styleCode: $styleCode,
                displayName: $displayName,
                parentPublicId: null,
                parentStyleCode: null,
                parentDisplayName: null,
                sortOrder: 0,
                isActive: true,
                isDeleted: false,
                rowVersion: 'v1',
                inheritance: ProductStyleInheritanceConfig::localAll(),
            );
        }

        $repository = $this->createStub(ProductStyleRepository::class);
        $repository->method('listAll')->willReturn($records);

        return new InMemoryAssistantStyleOptionsProvider($repository);
    }
}
