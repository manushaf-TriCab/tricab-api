<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Assistant;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Assistant\Application\AssistantCatalogueVisibility;
use TriCab\Api\Assistant\Application\AssistantProductGrounder;
use TriCab\Api\Assistant\Application\AssistantCodeProduct;
use TriCab\Api\Assistant\Application\AssistantCodeProductLookup;
use TriCab\Api\Assistant\Application\AssistantStyleOptionsProvider;
use TriCab\Api\Assistant\Application\AssistantToolResult;
use TriCab\Api\Assistant\Infrastructure\InMemoryAssistantCodeProductLookup;
use TriCab\Api\Assistant\Infrastructure\ProductAssistantToolExecutor;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProduct3DModelRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductCodeSearchRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductCustomerViewRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductPublicQueryRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductSearchRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductStore;
use TriCab\Core\CableCalculator\Application\Service\CalculateCableService;
use TriCab\Core\CableCalculator\Contract\CableCalculatorEngine;
use TriCab\Core\Product\Application\Service\GetAdminProductCustomerViewService;
use TriCab\Core\Product\Application\Service\GetPublicProductService;
use TriCab\Core\Product\Application\Service\SearchProductCodesService;
use TriCab\Core\Product\Application\Service\SearchProductsService;
use TriCab\Core\ProductFinder\Application\Client\NullProductFinderClient;
use TriCab\Core\ProductFinder\Application\DTO\ProductFinderQuery;
use TriCab\Core\ProductFinder\Application\DTO\ProductFinderResult;
use TriCab\Core\ProductFinder\Contract\ProductFinderClient;

final class ProductAssistantToolExecutorTest extends TestCase
{
    public function test_a_stated_construction_carries_no_sizing_caveat(): void
    {
        // "KL 1C 95mm in orange" states the construction outright, so the
        // finder resolves it without sizing anything and returns `sizing: null`.
        // Caveating sizing that did not happen gave the agent a reason to
        // distrust a correct answer and reach for `search_product_codes`, which
        // cannot match a colour word — the reported "there is no orange option".
        $executor = $this->executor($this->finderReturning([
            'status' => 'resolved',
            'productCode' => 'KL-PAXN/1C95OR',
            'stocked' => true,
            'confidence' => 0.78,
            'sizing' => null,
            'codeSegments' => ['style' => 'KL', 'jacketColour' => 'OR'],
            'warnings' => ['Construction class not stated; defaulted to PA (KL standard). Confirm.'],
        ]));

        $result = $executor->execute(
            'find_product',
            ['text' => 'KL 1C 95mm', 'colour' => 'orange'],
            null,
            AssistantCatalogueVisibility::PUBLIC,
        );

        $advisories = self::advisories($result);
        self::assertSame(
            ['Stock status is incomplete and must not be presented as a confirmed live availability promise.'],
            $advisories,
        );
        self::assertStringNotContainsStringIgnoringCase('sizing', implode(' ', $advisories));

        // Nor on the card the customer sees.
        $warnings = self::cardWarnings($result);
        self::assertStringNotContainsStringIgnoringCase('sizing', implode(' ', $warnings));
        // The finder's own construction warning still reaches the agent.
        self::assertContains(
            'Construction class not stated; defaulted to PA (KL standard). Confirm.',
            $warnings,
        );
    }

    public function test_find_product_returns_the_query_as_the_reply_spec(): void
    {
        $executor = $this->executor($this->finderReturning([
            'status' => 'resolved',
            'productCode' => null,
            'sizing' => null,
        ]));

        $result = $executor->execute(
            'find_product',
            ['style' => 'XL', 'coreCount' => 4, 'colour' => 'orange', 'install' => 5, 'voltage' => 400],
            null,
            AssistantCatalogueVisibility::PUBLIC,
        );

        self::assertIsArray($result->spec);
        self::assertSame(4, $result->spec['coreCount']);
        self::assertSame('Orange', $result->spec['colourLabel']);
        self::assertSame(['code' => 5, 'label' => 'Conduit in air'], $result->spec['installMethod']);
        self::assertSame('400 / 415 V', $result->spec['voltageLabel']);
    }

    public function test_the_card_carries_the_request_beside_the_code(): void
    {
        // "4 core 16mm2 flexible" came back as XL-PAXN/4C16OR, which is 3C + E.
        // The card needs the request to show the customer what differs; the
        // agent's parameters win over what the finder read from the text.
        $executor = $this->executor($this->finderReturning([
            'status' => 'resolved',
            'productCode' => 'XL-PAXN/4C16OR',
            'stocked' => true,
            'confidence' => 0.67,
            'sizing' => null,
            'codeSegments' => ['style' => 'XL', 'conductorCount' => 4, 'crossSection' => '16'],
            'understood' => ['mode' => 'text', 'fields' => ['count' => 3, 'cross_section' => '16', 'colour' => 'OR']],
            'warnings' => [
                'Core count was not found.',
                'Conductor cross-section was not found.',
                'Colour not stated; defaulted to OR (style standard). Confirm.',
            ],
        ]));

        $result = $executor->execute(
            'find_product',
            ['text' => '4 core 16mm2 flexible cable', 'coreCount' => 4],
            null,
            AssistantCatalogueVisibility::PUBLIC,
        );

        self::assertSame(
            ['coreCount' => 4, 'crossSectionMm2' => 16.0, 'colour' => 'OR', 'style' => null],
            $result->cards[0]['requested'] ?? null,
        );

        // The code answers both "not found" notes, so they no longer reach the card.
        $warnings = self::cardWarnings($result);
        self::assertNotContains('Core count was not found.', $warnings);
        self::assertNotContains('Conductor cross-section was not found.', $warnings);
        self::assertContains('Colour not stated; defaulted to OR (style standard). Confirm.', $warnings);
    }

    public function test_the_card_carries_the_catalogue_product_picture_and_links(): void
    {
        $lookup = new InMemoryAssistantCodeProductLookup();
        $lookup->add('M4P-PAXN/3C16BK', new AssistantCodeProduct(
            imageUrl: 'https://tricabv2.blob.core.windows.net/products/m4p.png?sig=read',
            imageBackgroundColor: '#E5DBCF',
            productSlug: 'rfob-m4p-3c',
            variantPublicId: null,
        ));
        $executor = $this->executor($this->finderReturning([
            'status' => 'resolved',
            'productCode' => 'M4P-PAXN/3C16BK',
            'stocked' => null,
            'confidence' => 0.92,
            'sizing' => null,
            'codeSegments' => ['style' => 'M4P', 'conductorCount' => 3],
            'warnings' => [],
        ]), null, $lookup);

        $card = $executor->execute('find_product', ['text' => 'M4P 3 core 16'], null, AssistantCatalogueVisibility::PUBLIC)->cards[0];

        self::assertSame(
            ['url' => 'https://tricabv2.blob.core.windows.net/products/m4p.png?sig=read', 'backgroundColor' => '#E5DBCF'],
            $card['image'] ?? null,
        );
        self::assertSame('rfob-m4p-3c', $card['productSlug']);
        // A style's stand-in product never supplies a variant: adding it would quote another code.
        self::assertNull($card['variantPublicId']);
    }

    public function test_a_code_with_no_catalogue_product_has_no_picture(): void
    {
        $executor = $this->executor($this->finderReturning([
            'status' => 'resolved',
            'productCode' => 'XL-PAXN/4C16OR',
            'stocked' => true,
            'confidence' => 0.67,
            'sizing' => null,
            'codeSegments' => ['style' => 'XL'],
            'warnings' => [],
        ]));

        $card = $executor->execute('find_product', ['text' => '4 core 16'], null, AssistantCatalogueVisibility::PUBLIC)->cards[0];

        self::assertArrayHasKey('image', $card);
        self::assertNull($card['image']);
        self::assertNull($card['productSlug']);
    }

    public function test_a_card_with_nothing_requested_says_so(): void
    {
        $executor = $this->executor($this->finderReturning([
            'status' => 'resolved',
            'productCode' => 'KL-PAXN/1C95OR',
            'stocked' => true,
            'confidence' => 0.9,
            'sizing' => null,
            'codeSegments' => ['style' => 'KL'],
            'warnings' => [],
        ]));

        $result = $executor->execute('find_product', ['text' => 'earth cable'], null, AssistantCatalogueVisibility::PUBLIC);

        self::assertArrayHasKey('requested', $result->cards[0]);
        self::assertNull($result->cards[0]['requested']);
    }

    public function test_a_sized_answer_still_carries_the_sizing_caveat(): void
    {
        $executor = $this->executor($this->finderReturning([
            'status' => 'resolved',
            'productCode' => 'XL-PBXN/4C16OR',
            'stocked' => false,
            'confidence' => 0.9,
            'sizing' => [
                'status' => 'sized',
                'candidate' => ['spec' => ['crossSection' => 16.0], 'runs' => 1],
            ],
            'codeSegments' => ['style' => 'XL'],
            'warnings' => [],
        ]));

        $result = $executor->execute(
            'find_product',
            ['text' => '4 core feeding a motor 80 m away at 200 A'],
            null,
            AssistantCatalogueVisibility::PUBLIC,
        );

        self::assertCount(2, self::advisories($result));
        self::assertStringContainsString(
            '29% of the measured cases',
            implode(' ', self::advisories($result)),
        );
        self::assertStringContainsString(
            'Sizing is advisory',
            implode(' ', self::cardWarnings($result)),
        );
    }

    public function test_an_unavailable_finder_is_not_reported_as_advisory_sizing(): void
    {
        // `ProductFinderResult::unavailable()` fills `sizing` with
        // `['available' => false, …]`. That is an array, so a bare `is_array`
        // check would read the service being down as a sizing run and attach a
        // caveat about a dataset that was never consulted.
        $executor = $this->executor(new NullProductFinderClient());

        $result = $executor->execute(
            'find_product',
            ['text' => 'KL 1C 95mm', 'colour' => 'orange'],
            null,
            AssistantCatalogueVisibility::PUBLIC,
        );

        self::assertStringNotContainsStringIgnoringCase(
            'sizing is advisory',
            implode(' ', self::advisories($result)),
        );
        // No code, so no card to caveat either way.
        self::assertSame([], $result->cards);
    }

    public function test_find_product_takes_a_colour_word_and_passes_it_through(): void
    {
        // The whole point of routing this shape to the finder: the segment code
        // is the rules package's business, not the agent's.
        $captured = null;
        $client = new class ($captured) implements ProductFinderClient {
            public function __construct(public ?ProductFinderQuery $seen) {}

            public function find(ProductFinderQuery $query): ProductFinderResult
            {
                $this->seen = $query;

                return ProductFinderResult::fromFinder([
                    'productCode' => 'KL-PAXN/1C95OR',
                    'sizing' => null,
                    'codeSegments' => [],
                    'warnings' => [],
                ]);
            }

            public function isAvailable(): bool
            {
                return true;
            }
        };

        $this->executor($client)->execute(
            'find_product',
            ['text' => 'KL 1C 95mm', 'colour' => 'orange'],
            null,
            AssistantCatalogueVisibility::PUBLIC,
        );

        self::assertSame('orange', $client->seen?->colour);
    }

    public function test_a_truncated_style_page_says_so(): void
    {
        // Silence here was the bug: 30-of-145 read as the whole catalogue, and
        // the agent answered that earthing is "not a distinct style category"
        // when LP and LS simply sat past the alphabetical cut-off.
        $styles = $this->createStub(AssistantStyleOptionsProvider::class);
        $styles->method('search')->willReturn([
            'items' => [['styleCode' => 'AD', 'displayName' => 'Armoured Data', 'facets' => []]],
            'totalItems' => 145,
            'page' => 1,
            'pageSize' => 1,
        ]);

        $data = $this->executor(new NullProductFinderClient(), $styles)
            ->execute('list_style_options', [], null, AssistantCatalogueVisibility::PUBLIC)
            ->data;

        $pagination = $data['pagination'];
        self::assertIsArray($pagination);
        self::assertSame(145, $pagination['totalItems']);
        self::assertSame(145, $pagination['totalPages']);
        $coverage = $data['coverage'];
        self::assertIsString($coverage);
        self::assertStringContainsString('Showing 1 of 145', $coverage);
        self::assertStringContainsString('not the whole answer', $coverage);
    }

    public function test_a_complete_style_page_says_that_instead(): void
    {
        $styles = $this->createStub(AssistantStyleOptionsProvider::class);
        $styles->method('search')->willReturn([
            'items' => [
                ['styleCode' => 'LP', 'displayName' => 'Flexible Rubber Building Wire', 'facets' => []],
                ['styleCode' => 'LS', 'displayName' => 'Flexible LSZH Switchboard/ Panel Wire', 'facets' => []],
            ],
            'totalItems' => 2,
            'page' => 1,
            'pageSize' => 20,
        ]);

        $data = $this->executor(new NullProductFinderClient(), $styles)
            ->execute('list_style_options', ['text' => 'earthing'], null, AssistantCatalogueVisibility::PUBLIC)
            ->data;

        self::assertSame('Showing all 2 matching styles.', $data['coverage']);
        self::assertSame([], $this->executor(new NullProductFinderClient(), $styles)
            ->execute('list_style_options', ['text' => 'earthing'], null, AssistantCatalogueVisibility::PUBLIC)
            ->cards, 'a style answer offers no product cards');
    }

    public function test_style_search_takes_free_text_for_what_a_cable_is_for(): void
    {
        $data = $this->executor(new NullProductFinderClient())
            ->definitions();
        $schema = null;
        foreach ($data as $definition) {
            if ($definition['name'] === 'list_style_options') {
                $schema = $definition['input_schema'];
            }
        }
        self::assertIsArray($schema);
        $properties = $schema['properties'];
        self::assertIsArray($properties);
        self::assertArrayHasKey('text', $properties);
        self::assertArrayHasKey('page', $properties);
        self::assertArrayNotHasKey('limit', $properties, 'limit was replaced by page/pageSize');
    }

    public function test_the_colour_facets_tell_the_agent_which_form_each_tool_wants(): void
    {
        // The original defect was silent: `jacketColour` is matched exactly, so
        // "orange" returned nothing and the agent concluded orange did not
        // exist. Both schemas must now say which form they take.
        $definitions = $this->executor(new NullProductFinderClient())->definitions();

        self::assertStringContainsString(
            'OR',
            self::facetDescription($definitions, 'search_product_codes', 'jacketColour'),
        );
        self::assertStringContainsString(
            'orange',
            self::facetDescription($definitions, 'find_product', 'colour'),
        );

        // Tool input must never be able to broaden a server-authorized query.
        foreach ($definitions as $definition) {
            $schema = $definition['input_schema'];
            self::assertIsArray($schema);
            self::assertFalse($schema['additionalProperties']);
        }
    }

    /** @return list<string> */
    private static function advisories(AssistantToolResult $result): array
    {
        return self::strings($result->data['assistantAdvisories'] ?? null);
    }

    /** @return list<string> */
    private static function cardWarnings(AssistantToolResult $result): array
    {
        self::assertNotSame([], $result->cards, 'Expected a product-code card.');

        return self::strings($result->cards[0]['warnings'] ?? null);
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        self::assertIsArray($value);
        $strings = [];
        foreach ($value as $entry) {
            self::assertIsString($entry);
            $strings[] = $entry;
        }

        return $strings;
    }

    /**
     * @param list<array<string, mixed>> $definitions
     */
    private static function facetDescription(array $definitions, string $tool, string $facet): string
    {
        foreach ($definitions as $definition) {
            if (($definition['name'] ?? null) !== $tool) {
                continue;
            }
            $schema = $definition['input_schema'];
            self::assertIsArray($schema);
            $properties = $schema['properties'];
            self::assertIsArray($properties);
            $property = $properties[$facet] ?? null;
            self::assertIsArray($property);
            $description = $property['description'] ?? null;
            self::assertIsString($description);

            return $description;
        }

        self::fail("No {$tool} tool is defined.");
    }

    /** @param array<string, mixed> $data */
    private function finderReturning(array $data): ProductFinderClient
    {
        return new class ($data) implements ProductFinderClient {
            /** @param array<string, mixed> $data */
            public function __construct(private array $data) {}

            public function find(ProductFinderQuery $query): ProductFinderResult
            {
                unset($query);

                return ProductFinderResult::fromFinder($this->data);
            }

            public function isAvailable(): bool
            {
                return true;
            }
        };
    }

    private function executor(
        ProductFinderClient $finder,
        ?AssistantStyleOptionsProvider $styles = null,
        ?AssistantCodeProductLookup $codeProducts = null,
    ): ProductAssistantToolExecutor {
        // Only the finder seam matters here; the other tools have their own
        // tests and `find_product` never reaches them. The services are final,
        // so they are built for real over the in-memory repositories rather
        // than doubled.
        $store = new InMemoryProductStore();
        $threeDModels = new InMemoryProduct3DModelRepository($store);

        return new ProductAssistantToolExecutor(
            new SearchProductCodesService(new InMemoryProductCodeSearchRepository()),
            $finder,
            new CalculateCableService($this->createStub(CableCalculatorEngine::class)),
            new SearchProductsService(new InMemoryProductSearchRepository($store)),
            new AssistantProductGrounder(
                new GetPublicProductService(new InMemoryProductPublicQueryRepository($store, $threeDModels)),
                new GetAdminProductCustomerViewService(
                    new InMemoryProductCustomerViewRepository($store, $threeDModels),
                ),
            ),
            $styles ?? $this->createStub(AssistantStyleOptionsProvider::class),
            $codeProducts ?? new InMemoryAssistantCodeProductLookup(),
        );
    }
}
