<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Infrastructure;

use InvalidArgumentException;
use TriCab\Api\Assistant\Application\AssistantCatalogueVisibility;
use TriCab\Api\Assistant\Application\AssistantProductGrounder;
use TriCab\Api\Assistant\Application\AssistantReplySpec;
use TriCab\Api\Assistant\Application\AssistantCodeProductLookup;
use TriCab\Api\Assistant\Application\AssistantStyleOptionsProvider;
use TriCab\Api\Assistant\Application\AssistantToolExecutor;
use TriCab\Api\Assistant\Application\AssistantToolResult;
use TriCab\Api\Assistant\Application\GroundedProduct;
use TriCab\Api\Http\Action\Admin\ProductManagementDtoSerializer;
use TriCab\Api\Http\Action\CableCalculator\CableCalculatorDtoSerializer;
use TriCab\Api\Http\Action\CableCalculator\CableCalculatorRequestMapper;
use TriCab\Core\CableCalculator\Application\Service\CalculateCableService;
use TriCab\Core\Product\Application\DTO\ProductCodeSearchCriteria;
use TriCab\Core\Product\Application\DTO\ProductSearchCriteria;
use TriCab\Core\Product\Application\Service\SearchProductCodesService;
use TriCab\Core\Product\Application\Service\SearchProductsService;
use TriCab\Core\ProductFinder\Application\DTO\ProductFinderQuery;
use TriCab\Core\ProductFinder\Contract\ProductFinderClient;

/**
 * The agent's only route to application data. Region and visibility are always
 * supplied by the server; model input cannot broaden either scope.
 */
final readonly class ProductAssistantToolExecutor implements AssistantToolExecutor
{
    private const MAX_RESULTS = 12;

    /**
     * Higher than MAX_RESULTS on purpose. A style row is a short facet summary,
     * not a product projection, and 145 styles carry facets — a cap of 30 with
     * no paging is what made most of the register unreachable.
     */
    private const MAX_STYLE_RESULTS = 50;

    public function __construct(
        private SearchProductCodesService $searchProductCodes,
        private ProductFinderClient $productFinder,
        private CalculateCableService $calculateCable,
        private SearchProductsService $searchProducts,
        private AssistantProductGrounder $productGrounder,
        private AssistantStyleOptionsProvider $styleOptions,
        private AssistantCodeProductLookup $codeProducts,
    ) {
    }

    public function definitions(): array
    {
        return [
            $this->tool('search_product_codes', 'Search real TriCab product codes by text and structured cable facets. Use this to verify that a code exists and inspect its nine segments; it does not size a cable.', [
                'text' => ['type' => 'string'],
                'styleCodes' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 12, 'description' => 'Style codes to look up together, so one call compares them all.'],
                'cableType' => ['type' => 'string'],
                'voltageClass' => ['type' => 'string'],
                'coreCount' => ['type' => 'integer', 'minimum' => 1],
                'coreCountMin' => ['type' => 'integer', 'minimum' => 1],
                'coreCountMax' => ['type' => 'integer', 'minimum' => 1],
                'coreType' => ['type' => 'string'],
                'crossSectionMm2Min' => ['type' => 'number', 'exclusiveMinimum' => 0],
                'crossSectionMm2Max' => ['type' => 'number', 'exclusiveMinimum' => 0],
                'conductorCode' => ['type' => 'string'],
                'jacketColour' => [
                    'type' => 'string',
                    'description' => 'Two-character sheath colour segment code, matched exactly: BK black, OR orange, RE red, GY grey, BL blue, WH white, EA green/yellow earth, YE yellow, BR brown, GR green, DB dark blue, LB light blue, PU purple, PI pink, CL clear. A colour word ("orange") matches nothing. If you are unsure of the code, use find_product, which takes the word.',
                ],
                'braidArmourScreen' => ['type' => 'string'],
                'isLszh' => ['type' => 'boolean'],
                'isFireResistant' => ['type' => 'boolean'],
                'isArmoured' => ['type' => 'boolean'],
                'hasScreen' => ['type' => 'boolean'],
                'stockStatuses' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => ProductCodeSearchCriteria::STOCK_STATUSES],
                    'maxItems' => 3,
                ],
                'isCurrent' => ['type' => 'boolean'],
                'page' => ['type' => 'integer', 'minimum' => 1],
                'pageSize' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_RESULTS],
            ]),
            $this->tool('find_product', 'Turn a cable requirement into a TriCab product-code recommendation using the corrected product finder. Use it for a stated construction (style, cores, size, colour) as well as for a sizing question — it resolves the construction whether or not an electrical duty is given, and it is the only tool that understands colour words and style synonyms. It may ask for clarification. Any sizing and stock fields it returns are advisory because their source datasets are still under Technical review. Every call needs `text`, `cableType` or `style`; one with only numbers (load, length, phase) is rejected, so always pass the requirement in `text` as well.', [
                'text' => [
                    'type' => 'string',
                    'description' => 'The requirement in the customer\'s words, e.g. "3 phase 100 A submain, 80 m, armoured". Pass it on every call.',
                ],
                'cableType' => ['type' => 'integer'],
                'style' => ['type' => 'string'],
                'coreCount' => ['type' => 'integer', 'minimum' => 1],
                'crossSectionMm2' => ['type' => 'number', 'exclusiveMinimum' => 0],
                'colour' => [
                    'type' => 'string',
                    'description' => 'Sheath colour, as a plain word ("orange") or the two-character segment code ("OR"). The selection rules normalise it, so pass what the customer said. Unlike search_product_codes.jacketColour this does not need the segment code.',
                ],
                'loadAmps' => ['type' => 'number', 'exclusiveMinimum' => 0],
                'lengthM' => ['type' => 'number', 'exclusiveMinimum' => 0],
                'voltage' => ['type' => 'integer', 'minimum' => 1],
                'phase' => ['type' => 'integer', 'enum' => [3, 4, 5], 'description' => 'Phase code, not a count: 3 three phase, 4 single phase, 5 DC.'],
                'install' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 9, 'description' => 'Installation method code: 1 unenclosed spaced (cable tray, ladder), 2 unenclosed touching, 3 buried in spaced conduit, 4 buried spaced, 5 conduit in air ("in conduit" above ground), 6 exposed to sun, 7 buried in touching conduit, 8 IEC, 9 buried touching. Omit it when the customer has not said.'],
                'ambient' => ['type' => 'integer'],
                'strategy' => ['type' => 'string'],
            ]),
            $this->tool('calculate_cable', 'Run the existing legacy-parity cable calculator. Use only when the necessary electrical duty is known. Its generated legacy code is not proof that a catalogue product code exists.', [
                'reference' => ['type' => 'string'],
                'loadAmps' => ['type' => 'number', 'exclusiveMinimum' => 0],
                'runLengthM' => ['type' => 'number', 'minimum' => 0],
                'powerFactor' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'maximumVoltageDropPercent' => ['type' => 'number', 'exclusiveMinimum' => 0],
                'voltage' => ['type' => 'integer', 'minimum' => 1],
                'phaseClass' => ['type' => 'integer', 'enum' => [3, 4, 5], 'description' => 'Phase code, not a count: 3 three phase, 4 single phase, 5 DC.'],
                'ambientTemperatureC' => ['type' => 'integer'],
                'maxOperatingTemperatureC' => ['type' => 'integer'],
                'activeConductorMaterial' => ['type' => 'string'],
                'earthConductorMaterial' => ['type' => 'string'],
                'cableType' => ['type' => 'integer'],
                'installationMethod' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 9, 'description' => 'Installation method code: 1 unenclosed spaced (cable tray, ladder), 2 unenclosed touching, 3 buried in spaced conduit, 4 buried spaced, 5 conduit in air ("in conduit" above ground), 6 exposed to sun, 7 buried in touching conduit, 8 IEC, 9 buried touching. Omit it when the customer has not said.'],
                'cableRunsMode' => ['type' => 'integer'],
                'fixedCableSize' => ['type' => 'string'],
                'earthResistanceMode' => ['type' => 'string'],
                'maximumFaultTimeSeconds' => ['type' => 'number'],
            ], ['loadAmps', 'runLengthM']),
            $this->tool('search_products', 'Search catalogue products by product name, category, series, code or descriptive text. This is product-level search, not product-code selection.', [
                'query' => ['type' => 'string', 'minLength' => 1],
                'page' => ['type' => 'integer', 'minimum' => 1],
                'pageSize' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_RESULTS],
            ], ['query']),
            $this->tool('get_product_detail', 'Get the server-authorized catalogue detail for one product returned by search_products. Pass its publicId where possible.', [
                'identifier' => ['type' => 'string', 'minLength' => 1],
            ], ['identifier']),
            $this->tool('list_style_options', 'Search real style names and their reviewed structured facets. Use it to answer a style-level question ("which styles suit earthing?", "what is the LSZH equivalent of KL?"), to compare candidate styles, and to formulate one targeted clarification question. This is the right tool when the customer asks about styles rather than a specific product code. Compare several styles in ONE call by passing all their codes in styleCodes (up to 12), and fetch everything you need for a style question in one call rather than one call per style; results are paged, so ask for the next page instead of repeating the search.', [
                'text' => [
                    'type' => 'string',
                    'description' => 'Keywords matched against the style code, display name and the style register line — which carries the application prose, the standards list, voltage class and temperature range. Every word must match, so "lszh earthing" narrows. Search by what the cable is FOR ("earthing", "switchboard", "trailing"), by standard ("AS/NZS 5000.1"), or by construction ("armoured", "screened").',
                ],
                'styleCodes' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 12, 'description' => 'Style codes to look up together, so one call compares them all.'],
                'cableType' => ['type' => 'string'],
                'page' => ['type' => 'integer', 'minimum' => 1],
                'pageSize' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
            ]),
        ];
    }

    public function execute(
        string $name,
        array $input,
        ?string $regionCode,
        AssistantCatalogueVisibility $catalogueVisibility,
    ): AssistantToolResult {
        return match ($name) {
            'search_product_codes' => $this->searchProductCodes($input, $catalogueVisibility),
            'find_product' => $this->findProduct($input, $regionCode),
            'calculate_cable' => $this->calculateCable($input),
            'search_products' => $this->searchProducts($input, $regionCode, $catalogueVisibility),
            'get_product_detail' => $this->getProductDetail($input, $regionCode, $catalogueVisibility),
            'list_style_options' => $this->listStyleOptions($input),
            default => throw new InvalidArgumentException('Unknown assistant tool.'),
        };
    }

    /** @param array<string, mixed> $input */
    private function searchProductCodes(array $input, AssistantCatalogueVisibility $visibility): AssistantToolResult
    {
        $result = $this->searchProductCodes->execute(new ProductCodeSearchCriteria(
            text: $this->string($input, 'text'),
            styleCodes: $this->stringList($input, 'styleCodes'),
            cableType: $this->string($input, 'cableType'),
            voltageClass: $this->string($input, 'voltageClass'),
            coreCount: $this->int($input, 'coreCount'),
            coreCountMin: $this->int($input, 'coreCountMin'),
            coreCountMax: $this->int($input, 'coreCountMax'),
            coreType: $this->string($input, 'coreType'),
            crossSectionMm2Min: $this->float($input, 'crossSectionMm2Min'),
            crossSectionMm2Max: $this->float($input, 'crossSectionMm2Max'),
            conductorCode: $this->string($input, 'conductorCode'),
            jacketColour: $this->string($input, 'jacketColour'),
            braidArmourScreen: $this->string($input, 'braidArmourScreen'),
            isLszh: $this->bool($input, 'isLszh'),
            isFireResistant: $this->bool($input, 'isFireResistant'),
            isArmoured: $this->bool($input, 'isArmoured'),
            hasScreen: $this->bool($input, 'hasScreen'),
            stockStatuses: $this->stringList($input, 'stockStatuses'),
            isCurrent: $this->bool($input, 'isCurrent'),
            page: $this->int($input, 'page') ?? 1,
            pageSize: min(self::MAX_RESULTS, $this->int($input, 'pageSize') ?? 8),
            publishedOnly: $visibility->publishedOnly(),
        ));

        $styleCodes = $this->stringList($input, 'styleCodes');

        return new AssistantToolResult(data: [
            'items' => array_map(ProductManagementDtoSerializer::codeSearchResult(...), $result->items),
            'pagination' => [
                'page' => $result->pagination->page,
                'pageSize' => $result->pagination->pageSize,
                'totalItems' => $result->pagination->totalItems,
                'totalPages' => $result->pagination->totalPages,
            ],
            'stockStatusCoverage' => 'incomplete; null means unknown and STOCK/MAKE comes from a partial snapshot',
        ], cards: array_map(
            $this->codeSearchCard(...),
            array_slice($result->items, 0, 4),
        ), spec: AssistantReplySpec::fromCodeSearch(
            $this->int($input, 'coreCount'),
            $this->float($input, 'crossSectionMm2Min'),
            $this->float($input, 'crossSectionMm2Max'),
            $this->string($input, 'jacketColour'),
            count($styleCodes) === 1 ? $styleCodes[0] : null,
        ));
    }

    /** @param array<string, mixed> $input */
    private function findProduct(array $input, ?string $regionCode): AssistantToolResult
    {
        $query = new ProductFinderQuery(
            text: $this->string($input, 'text'),
            cableType: $this->int($input, 'cableType'),
            style: $this->string($input, 'style'),
            coreCount: $this->int($input, 'coreCount'),
            crossSectionMm2: $this->float($input, 'crossSectionMm2'),
            colour: $this->string($input, 'colour'),
            loadAmps: $this->float($input, 'loadAmps'),
            lengthM: $this->float($input, 'lengthM'),
            voltage: $this->int($input, 'voltage'),
            phase: $this->int($input, 'phase'),
            install: $this->int($input, 'install'),
            ambient: $this->int($input, 'ambient'),
            strategy: $this->string($input, 'strategy'),
            region: $regionCode,
            allowAi: false,
        );
        if ($query->isEmpty()) {
            throw new InvalidArgumentException('find_product needs text, cableType or style.');
        }

        $data = $this->productFinder->find($query)->toArray();

        // The stock caveat is always true. The sizing one is only true when the
        // finder actually sized something: a stated construction with no
        // electrical duty comes back with `sizing: null`, and caveating absent
        // sizing gives the agent a reason to distrust a correct construction
        // answer and go looking for a second opinion it cannot get.
        $advisories = [
            'Stock status is incomplete and must not be presented as a confirmed live availability promise.',
        ];
        if (self::wasSized($data)) {
            $advisories[] = 'Sizing is advisory: Technical has not yet approved the finder sizing snapshot, which differs from live MySQL for 29% of the measured cases.';
        }
        $data['assistantAdvisories'] = $advisories;

        $productCode = $data['productCode'] ?? null;

        return new AssistantToolResult(
            data: $data,
            cards: is_string($productCode) && trim($productCode) !== ''
                ? [$this->finderCodeCard($data, $query)]
                : [],
            spec: AssistantReplySpec::fromFinderQuery($query),
        );
    }

    /** @param array<string, mixed> $input */
    private function calculateCable(array $input): AssistantToolResult
    {
        $request = CableCalculatorRequestMapper::fromHttpBody($input);
        $result = $this->calculateCable->calculate($request);
        $data = CableCalculatorDtoSerializer::result($result);
        $data['assistantAdvisory'] = 'This is the legacy-parity calculator result. Generated legacy codes must be verified with search_product_codes before being described as catalogue codes.';

        return new AssistantToolResult($data);
    }

    /** @param array<string, mixed> $input */
    private function searchProducts(
        array $input,
        ?string $regionCode,
        AssistantCatalogueVisibility $visibility,
    ): AssistantToolResult {
        $query = $this->requiredString($input, 'query');
        $result = $this->searchProducts->execute(new ProductSearchCriteria(
            query: $query,
            page: $this->int($input, 'page') ?? 1,
            pageSize: min(self::MAX_RESULTS, $this->int($input, 'pageSize') ?? 8),
            regionCode: $regionCode,
            publishedOnly: $visibility->publishedOnly(),
            statusCodes: $visibility->allowedStatusCodes(),
        ));

        $products = array_map(
            fn ($item): GroundedProduct => $this->productGrounder->fromSearchResult($item, $regionCode, $visibility),
            $result->items,
        );

        return new AssistantToolResult(
            data: [
                'items' => array_map($this->curatedProduct(...), $products),
                'pagination' => [
                    'page' => $result->pagination->page,
                    'pageSize' => $result->pagination->pageSize,
                    'totalItems' => $result->pagination->totalItems,
                    'totalPages' => $result->pagination->totalPages,
                ],
            ],
            cards: array_map(static fn (GroundedProduct $product): array => $product->toCard(), $products),
        );
    }

    /** @param array<string, mixed> $input */
    private function getProductDetail(
        array $input,
        ?string $regionCode,
        AssistantCatalogueVisibility $visibility,
    ): AssistantToolResult {
        $product = $this->productGrounder->byIdentifier(
            $this->requiredString($input, 'identifier'),
            $regionCode,
            $visibility,
        );

        return new AssistantToolResult(
            data: $this->curatedProduct($product),
            cards: [$product->toCard()],
        );
    }

    /** @param array<string, mixed> $input */
    private function listStyleOptions(array $input): AssistantToolResult
    {
        $result = $this->styleOptions->search(
            $this->stringList($input, 'styleCodes'),
            $this->string($input, 'text'),
            $this->string($input, 'cableType'),
            $this->int($input, 'page') ?? 1,
            min(self::MAX_STYLE_RESULTS, $this->int($input, 'pageSize') ?? 20),
        );
        $shown = count($result['items']);

        return new AssistantToolResult([
            'styles' => $result['items'],
            'pagination' => [
                'page' => $result['page'],
                'pageSize' => $result['pageSize'],
                'totalItems' => $result['totalItems'],
                'totalPages' => $result['pageSize'] > 0
                    ? (int) ceil($result['totalItems'] / $result['pageSize'])
                    : 0,
            ],
            // Said out loud because the silent version of this was the bug: a
            // truncated list read as the whole catalogue, and the agent
            // concluded a style category did not exist when it simply sat
            // past the cut-off.
            'coverage' => $shown < $result['totalItems']
                ? sprintf(
                    'Showing %d of %d matching styles. This is a page, not the whole answer — narrow with text or request the next page before concluding a style does not exist.',
                    $shown,
                    $result['totalItems'],
                )
                : sprintf('Showing all %d matching styles.', $result['totalItems']),
            'classificationReview' => 'Some styles and facets are unknown pending Technical review; absence is not false.',
        ]);
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     * @param list<string> $required
     * @return array<string, mixed>
     */
    private function tool(string $name, string $description, array $properties, array $required = []): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'input_schema' => [
                'type' => 'object',
                'properties' => $properties,
                'required' => $required,
                'additionalProperties' => false,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function curatedProduct(GroundedProduct $product): array
    {
        $data = $product->toGroundingArray();
        $data['applications'] = array_slice($product->applications, 0, 8);
        $data['specifications'] = array_slice($product->specifications, 0, 16);

        return $data;
    }

    /** @return array<string, mixed> */
    private function codeSearchCard(\TriCab\Core\Product\Application\DTO\ProductCodeSearchResult $result): array
    {
        $serialized = ProductManagementDtoSerializer::codeSearchResult($result);
        $product = $this->codeProducts->forCode($result->codeValue, $result->styleCode, $result->coreCount);

        return [
            'type' => 'product-code',
            'codeValue' => $result->codeValue,
            'title' => $result->productName ?? $result->styleName ?? $result->styleCode,
            'description' => $result->description,
            'segments' => $serialized['segments'],
            'stockStatus' => $result->stockStatus,
            'isCurrent' => $result->isCurrent,
            'matchTier' => $result->matchTier,
            'confidence' => null,
            'productSlug' => $result->productSlug ?? $product?->productSlug,
            'variantPublicId' => $result->variantPublicId ?? $product?->variantPublicId,
            'image' => $product?->image(),
            'working' => null,
            'warnings' => [
                'Stock status comes from a partial snapshot; an empty status means unknown.',
            ],
        ];
    }

    /**
     * Whether the finder actually sized a cable, as opposed to resolving a
     * construction the customer stated outright.
     *
     * Three shapes reach here and only the first is sized. A sizing run returns
     * the `SizingResult` payload; a stated construction with no electrical duty
     * returns `sizing: null`, so a bare `isset` would read it as sized; and
     * `ProductFinderResult::unavailable()` returns `['available' => false, …]`,
     * which is an array and would fool a bare `is_array`.
     *
     * @param array<string, mixed> $data
     */
    private static function wasSized(array $data): bool
    {
        $sizing = $data['sizing'] ?? null;

        return is_array($sizing)
            && $sizing !== []
            && ($sizing['available'] ?? null) !== false;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function finderCodeCard(array $data, ProductFinderQuery $query): array
    {
        $segments = is_array($data['codeSegments'] ?? null) ? $data['codeSegments'] : [];
        $sizing = is_array($data['sizing'] ?? null) ? $data['sizing'] : [];
        $candidate = is_array($sizing['candidate'] ?? null) ? $sizing['candidate'] : [];
        $spec = is_array($candidate['spec'] ?? null) ? $candidate['spec'] : [];
        $style = is_array($data['style'] ?? null) ? $data['style'] : [];
        $warnings = is_array($data['warnings'] ?? null)
            ? array_values(array_filter($data['warnings'], 'is_string'))
            : [];
        // The extractor reports "not found" for the text it parsed, and the note
        // survives even when the agent passed the field as a parameter and the
        // code carries it. On the card that reads as the card contradicting itself.
        $warnings = array_values(array_filter(
            $warnings,
            static fn (string $warning): bool => match ($warning) {
                'Core count was not found.' => ($segments['conductorCount'] ?? null) === null,
                'Conductor cross-section was not found.' => ($segments['crossSection'] ?? null) === null,
                default => true,
            },
        ));
        if (self::wasSized($data)) {
            $warnings[] = 'Sizing is advisory until Technical approves the finder sizing snapshot.';
        }
        $warnings[] = 'Stock status is incomplete and is not a confirmed live availability promise.';
        $codeCount = $segments['conductorCount'] ?? null;
        $product = $this->codeProducts->forCode(
            is_string($data['productCode'] ?? null) ? $data['productCode'] : '',
            is_string($segments['style'] ?? null) ? $segments['style'] : null,
            is_int($codeCount) || (is_string($codeCount) && ctype_digit($codeCount)) ? (int) $codeCount : null,
        );

        return [
            'type' => 'product-code',
            'codeValue' => $data['productCode'],
            'title' => is_string($style['title'] ?? null) && trim($style['title']) !== ''
                ? $style['title']
                : (is_string($style['code'] ?? null) ? $style['code'] : 'TriCab cable'),
            'description' => is_string($data['description'] ?? null) ? $data['description'] : null,
            'segments' => [
                'styleCode' => $segments['style'] ?? null,
                'styleSuffix' => null,
                'jacketTypeCode' => $segments['sheath'] ?? null,
                'coreColourConfig' => $segments['coreColourConfig'] ?? null,
                'braidArmourScreen' => $segments['braidArmourScreen'] ?? null,
                'conductorCode' => $segments['conductor'] ?? null,
                'coreCount' => $segments['conductorCount'] ?? null,
                'coreTypeCode' => $segments['coreType'] ?? null,
                'crossSection' => $segments['crossSection'] ?? null,
                'jacketColour' => $segments['jacketColour'] ?? null,
            ],
            'stockStatus' => ($data['stocked'] ?? null) === true
                ? 'STOCK'
                : (($data['stocked'] ?? null) === false ? 'MAKE' : null),
            'isCurrent' => true,
            'matchTier' => null,
            'confidence' => is_int($data['confidence'] ?? null) || is_float($data['confidence'] ?? null)
                ? $data['confidence']
                : null,
            'productSlug' => $product?->productSlug,
            'variantPublicId' => $product?->variantPublicId,
            'image' => $product?->image(),
            'working' => $candidate !== [] ? [
                'crossSectionMm2' => $spec['crossSection'] ?? null,
                'activeCores' => $spec['activeCores'] ?? null,
                'tableCurrentA' => $spec['tableCurrent'] ?? null,
                'tableAmbientC' => $spec['tableAmbient'] ?? null,
                'runs' => $candidate['runs'] ?? null,
                'deratingFactor' => $candidate['deratingFactor'] ?? null,
                'deratedCurrentPerRunA' => $candidate['deratedCurrentPerRun'] ?? null,
                'totalCapacityA' => $candidate['totalCapacityAmps'] ?? null,
                'operatingTemperatureC' => $candidate['operatingTempC'] ?? null,
                'voltageDropPercent' => $candidate['voltDropPct'] ?? null,
                'earthCrossSectionMm2' => $candidate['earthCrossSection'] ?? null,
                'faultRatingKa' => $candidate['faultRatingKa'] ?? null,
            ] : null,
            'warnings' => array_values(array_unique($warnings)),
            'requested' => self::requested($data, $query),
        ];
    }

    /**
     * What the customer asked for, as the finder was given it, so the card can
     * put the request beside a code that only comes close. Parameters the agent
     * passed win over what the finder read out of the free text, as they do in
     * the finder itself.
     *
     * @param array<string, mixed> $data
     * @return array{coreCount: ?int, crossSectionMm2: ?float, colour: ?string, style: ?string}|null
     */
    private static function requested(array $data, ProductFinderQuery $query): ?array
    {
        $understood = is_array($data['understood'] ?? null) ? $data['understood'] : [];
        $fields = is_array($understood['fields'] ?? null) ? $understood['fields'] : [];

        $count = $fields['count'] ?? null;
        $crossSection = $fields['cross_section'] ?? null;
        $colour = $fields['colour'] ?? null;

        $requested = [
            'coreCount' => $query->coreCount ?? (is_numeric($count) ? (int) $count : null),
            'crossSectionMm2' => $query->crossSectionMm2
                ?? (is_numeric($crossSection) ? (float) $crossSection : null),
            'colour' => $query->colour ?? (is_string($colour) && $colour !== '' ? $colour : null),
            'style' => $query->style,
        ];

        return array_filter($requested, static fn (mixed $value): bool => $value !== null) === []
            ? null
            : $requested;
    }

    /** @param array<string, mixed> $input */
    private function requiredString(array $input, string $key): string
    {
        $value = $this->string($input, $key);
        if ($value === null) {
            throw new InvalidArgumentException($key . ' is required.');
        }

        return $value;
    }

    /** @param array<string, mixed> $input */
    private function string(array $input, string $key): ?string
    {
        if (!array_key_exists($key, $input)) {
            return null;
        }
        if (!is_string($input[$key])) {
            throw new InvalidArgumentException($key . ' must be a string.');
        }
        $trimmed = trim($input[$key]);

        return $trimmed === '' ? null : $trimmed;
    }

    /** @param array<string, mixed> $input */
    private function int(array $input, string $key): ?int
    {
        if (!array_key_exists($key, $input)) {
            return null;
        }
        if (!is_int($input[$key])) {
            throw new InvalidArgumentException($key . ' must be an integer.');
        }

        return $input[$key];
    }

    /** @param array<string, mixed> $input */
    private function float(array $input, string $key): ?float
    {
        if (!array_key_exists($key, $input)) {
            return null;
        }
        if (!is_int($input[$key]) && !is_float($input[$key])) {
            throw new InvalidArgumentException($key . ' must be a number.');
        }

        return (float) $input[$key];
    }

    /** @param array<string, mixed> $input */
    private function bool(array $input, string $key): ?bool
    {
        if (!array_key_exists($key, $input)) {
            return null;
        }
        if (!is_bool($input[$key])) {
            throw new InvalidArgumentException($key . ' must be a boolean.');
        }

        return $input[$key];
    }

    /**
     * @param array<string, mixed> $input
     * @return list<string>
     */
    private function stringList(array $input, string $key): array
    {
        if (!array_key_exists($key, $input)) {
            return [];
        }
        if (!is_array($input[$key]) || !array_is_list($input[$key])) {
            throw new InvalidArgumentException($key . ' must be a list of strings.');
        }
        $values = [];
        foreach ($input[$key] as $value) {
            if (!is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException($key . ' must contain non-empty strings.');
            }
            $values[] = trim($value);
        }

        return $values;
    }
}
