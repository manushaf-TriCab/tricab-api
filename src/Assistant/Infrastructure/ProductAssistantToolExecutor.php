<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Infrastructure;

use InvalidArgumentException;
use TriCab\Api\Assistant\Application\AssistantCatalogueVisibility;
use TriCab\Api\Assistant\Application\AssistantProductGrounder;
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

    public function __construct(
        private SearchProductCodesService $searchProductCodes,
        private ProductFinderClient $productFinder,
        private CalculateCableService $calculateCable,
        private SearchProductsService $searchProducts,
        private AssistantProductGrounder $productGrounder,
        private AssistantStyleOptionsProvider $styleOptions,
    ) {
    }

    public function definitions(): array
    {
        return [
            $this->tool('search_product_codes', 'Search real TriCab product codes by text and structured cable facets. Use this to verify that a code exists and inspect its nine segments; it does not size a cable.', [
                'text' => ['type' => 'string'],
                'styleCodes' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 12],
                'cableType' => ['type' => 'string'],
                'voltageClass' => ['type' => 'string'],
                'coreCount' => ['type' => 'integer', 'minimum' => 1],
                'coreCountMin' => ['type' => 'integer', 'minimum' => 1],
                'coreCountMax' => ['type' => 'integer', 'minimum' => 1],
                'coreType' => ['type' => 'string'],
                'crossSectionMm2Min' => ['type' => 'number', 'exclusiveMinimum' => 0],
                'crossSectionMm2Max' => ['type' => 'number', 'exclusiveMinimum' => 0],
                'conductorCode' => ['type' => 'string'],
                'jacketColour' => ['type' => 'string'],
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
            $this->tool('find_product', 'Turn a cable requirement into a TriCab product-code recommendation using the corrected product finder. It may ask for clarification. Any sizing and stock fields are advisory because their source datasets are still under Technical review.', [
                'text' => ['type' => 'string'],
                'cableType' => ['type' => 'integer'],
                'style' => ['type' => 'string'],
                'coreCount' => ['type' => 'integer', 'minimum' => 1],
                'crossSectionMm2' => ['type' => 'number', 'exclusiveMinimum' => 0],
                'loadAmps' => ['type' => 'number', 'exclusiveMinimum' => 0],
                'lengthM' => ['type' => 'number', 'exclusiveMinimum' => 0],
                'voltage' => ['type' => 'integer', 'minimum' => 1],
                'phase' => ['type' => 'integer'],
                'install' => ['type' => 'integer'],
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
                'phaseClass' => ['type' => 'integer'],
                'ambientTemperatureC' => ['type' => 'integer'],
                'maxOperatingTemperatureC' => ['type' => 'integer'],
                'activeConductorMaterial' => ['type' => 'string'],
                'earthConductorMaterial' => ['type' => 'string'],
                'cableType' => ['type' => 'integer'],
                'installationMethod' => ['type' => 'integer'],
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
            $this->tool('list_style_options', 'List real style names and their reviewed structured facets. Use this to compare candidate styles and formulate one targeted clarification question.', [
                'styleCodes' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 12],
                'cableType' => ['type' => 'string'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 30],
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
        $data['assistantAdvisories'] = [
            'Sizing is advisory: Technical has not yet approved the finder sizing snapshot, which differs from live MySQL for 29% of the measured cases.',
            'Stock status is incomplete and must not be presented as a confirmed live availability promise.',
        ];

        $productCode = $data['productCode'] ?? null;

        return new AssistantToolResult(
            data: $data,
            cards: is_string($productCode) && trim($productCode) !== ''
                ? [$this->finderCodeCard($data)]
                : [],
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
        return new AssistantToolResult([
            'styles' => $this->styleOptions->list(
                $this->stringList($input, 'styleCodes'),
                $this->string($input, 'cableType'),
                min(30, $this->int($input, 'limit') ?? 20),
            ),
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
            'productSlug' => $result->productSlug,
            'variantPublicId' => $result->variantPublicId,
            'working' => null,
            'warnings' => [
                'Stock status comes from a partial snapshot; an empty status means unknown.',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function finderCodeCard(array $data): array
    {
        $segments = is_array($data['codeSegments'] ?? null) ? $data['codeSegments'] : [];
        $sizing = is_array($data['sizing'] ?? null) ? $data['sizing'] : [];
        $candidate = is_array($sizing['candidate'] ?? null) ? $sizing['candidate'] : [];
        $spec = is_array($candidate['spec'] ?? null) ? $candidate['spec'] : [];
        $style = is_array($data['style'] ?? null) ? $data['style'] : [];
        $warnings = is_array($data['warnings'] ?? null)
            ? array_values(array_filter($data['warnings'], 'is_string'))
            : [];
        $warnings[] = 'Sizing is advisory until Technical approves the finder sizing snapshot.';
        $warnings[] = 'Stock status is incomplete and is not a confirmed live availability promise.';

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
            'productSlug' => null,
            'variantPublicId' => null,
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
        ];
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
