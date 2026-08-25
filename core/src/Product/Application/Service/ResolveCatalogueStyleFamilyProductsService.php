<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

/**
 * Resolves Products for a linked parent ProductStyle using persisted hierarchy +
 * ProductStyleAssignments. Does not use prefix matching. Cycle-safe.
 *
 * Includes Products assigned to the linked style itself (e.g. UP) plus all
 * descendant styles. Publication and region filters mirror public catalogue rules.
 */
final class ResolveCatalogueStyleFamilyProductsService
{
    private const MAX_TRAVERSAL_DEPTH = 64;

    /**
     * @param array<string, array<string, mixed>> $stylesByCode keyed by StyleCode
     * @param array<string, list<string>> $childrenByParentPublicId
     * @param array<string, list<array{productPublicId: string, productCode: string, productName: string}>> $productsByStylePublicId
     * @param array<string, array<string, mixed>> $productsByPublicId
     * @param array<string, list<string>> $regionCodesByProductPublicId
     */
    public function resolveForLinkedStyle(
        string $linkedStyleCode,
        array $stylesByCode,
        array $childrenByParentPublicId,
        array $productsByStylePublicId,
        array $productsByPublicId,
        array $regionCodesByProductPublicId,
        ?string $regionCode = null,
        bool $publicOnly = true,
    ): array {
        $linked = $stylesByCode[strtoupper(trim($linkedStyleCode))] ?? null;
        if ($linked === null) {
            return [
                'linkedStyleCode' => strtoupper(trim($linkedStyleCode)),
                'linkedStylePublicId' => null,
                'descendantStyleCodes' => [],
                'candidateProducts' => [],
                'visibleProducts' => [],
            ];
        }

        $descendantCodes = $this->collectDescendantStyleCodes(
            (string) $linked['publicId'],
            (string) $linked['styleCode'],
            $stylesByCode,
            $childrenByParentPublicId,
        );

        $candidate = [];
        foreach ($descendantCodes as $styleCode) {
            $style = $stylesByCode[$styleCode] ?? null;
            if ($style === null || empty($style['isActive']) || !empty($style['isDeleted'])) {
                continue;
            }
            $assignments = $productsByStylePublicId[(string) $style['publicId']] ?? [];
            foreach ($assignments as $row) {
                $productPublicId = (string) $row['productPublicId'];
                $product = $productsByPublicId[$productPublicId] ?? null;
                if ($product === null) {
                    continue;
                }
                $candidate[$productPublicId] = [
                    'productPublicId' => $productPublicId,
                    'productCode' => (string) ($row['productCode'] ?? $product['internalCode'] ?? ''),
                    'productName' => (string) ($row['productName'] ?? $product['productName'] ?? ''),
                    'resolvedViaStyleCode' => $styleCode,
                    'provenance' => 'STYLE',
                ];
            }
        }

        $visible = [];
        foreach ($candidate as $productPublicId => $row) {
            if (!$this->isPublicProduct($productsByPublicId[$productPublicId] ?? null, $publicOnly)) {
                continue;
            }
            if (!$this->isVisibleInRegion($productPublicId, $regionCode, $regionCodesByProductPublicId)) {
                continue;
            }
            $visible[$productPublicId] = $row;
        }

        uasort(
            $visible,
            static fn (array $a, array $b): int => [$a['productCode'], $a['productName']]
                <=> [$b['productCode'], $b['productName']],
        );

        return [
            'linkedStyleCode' => (string) $linked['styleCode'],
            'linkedStylePublicId' => (string) $linked['publicId'],
            'descendantStyleCodes' => array_values($descendantCodes),
            'candidateProducts' => array_values($candidate),
            'visibleProducts' => array_values($visible),
        ];
    }

    /**
     * Merge direct catalogue placements with style-resolved products.
     *
     * @param list<array{productPublicId: string, productCode?: string, productName?: string, displayOrder?: int}> $directProducts
     * @param list<array{productPublicId: string, productCode?: string, productName?: string, resolvedViaStyleCode?: string}> $styleProducts
     * @return array{
     *   products: list<array<string, mixed>>,
     *   duplicatesRemoved: int,
     *   directCount: int,
     *   styleOnlyCount: int,
     *   bothCount: int
     * }
     */
    public function mergeDirectAndStyleProducts(array $directProducts, array $styleProducts): array
    {
        $merged = [];
        $directIds = [];
        foreach ($directProducts as $row) {
            $id = (string) ($row['productPublicId'] ?? '');
            if ($id === '') {
                continue;
            }
            $directIds[$id] = true;
            $merged[$id] = [
                'productPublicId' => $id,
                'productCode' => (string) ($row['productCode'] ?? ''),
                'productName' => (string) ($row['productName'] ?? ''),
                'displayOrder' => (int) ($row['displayOrder'] ?? 0),
                'provenance' => 'DIRECT',
                'resolvedViaStyleCode' => null,
            ];
        }

        $duplicates = 0;
        $styleOnly = 0;
        $both = 0;
        foreach ($styleProducts as $row) {
            $id = (string) ($row['productPublicId'] ?? '');
            if ($id === '') {
                continue;
            }
            if (isset($merged[$id])) {
                ++$duplicates;
                ++$both;
                $merged[$id]['provenance'] = 'DIRECT+STYLE';
                if ($merged[$id]['resolvedViaStyleCode'] === null) {
                    $merged[$id]['resolvedViaStyleCode'] = $row['resolvedViaStyleCode'] ?? null;
                }
                continue;
            }
            ++$styleOnly;
            $merged[$id] = [
                'productPublicId' => $id,
                'productCode' => (string) ($row['productCode'] ?? ''),
                'productName' => (string) ($row['productName'] ?? ''),
                'displayOrder' => 999999,
                'provenance' => 'STYLE',
                'resolvedViaStyleCode' => $row['resolvedViaStyleCode'] ?? null,
            ];
        }

        $products = array_values($merged);
        usort(
            $products,
            static fn (array $a, array $b): int => [$a['displayOrder'], $a['productCode'], $a['productName']]
                <=> [$b['displayOrder'], $b['productCode'], $b['productName']],
        );

        return [
            'products' => $products,
            'duplicatesRemoved' => $duplicates,
            'directCount' => count($directIds),
            'styleOnlyCount' => $styleOnly,
            'bothCount' => $both,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $stylesByCode
     * @param array<string, list<string>> $childrenByParentPublicId
     * @return list<string> style codes including linked root
     */
    private function collectDescendantStyleCodes(
        string $rootPublicId,
        string $rootStyleCode,
        array $stylesByCode,
        array $childrenByParentPublicId,
    ): array {
        $codes = [strtoupper($rootStyleCode)];
        $seen = [$rootPublicId => true];
        $queue = [$rootPublicId];
        $depth = 0;

        while ($queue !== [] && $depth < self::MAX_TRAVERSAL_DEPTH) {
            $nextQueue = [];
            foreach ($queue as $parentPublicId) {
                foreach ($childrenByParentPublicId[$parentPublicId] ?? [] as $childPublicId) {
                    if (isset($seen[$childPublicId])) {
                        continue;
                    }
                    $seen[$childPublicId] = true;
                    $child = null;
                    foreach ($stylesByCode as $style) {
                        if (($style['publicId'] ?? '') === $childPublicId) {
                            $child = $style;
                            break;
                        }
                    }
                    if ($child === null || empty($child['isActive']) || !empty($child['isDeleted'])) {
                        continue;
                    }
                    $codes[] = strtoupper((string) $child['styleCode']);
                    $nextQueue[] = $childPublicId;
                }
            }
            $queue = $nextQueue;
            ++$depth;
        }

        return array_values(array_unique($codes));
    }

    /**
     * @param array<string, mixed>|null $product
     */
    private function isPublicProduct(?array $product, bool $publicOnly): bool
    {
        if ($product === null) {
            return false;
        }
        if (!$publicOnly) {
            return true;
        }
        if (!empty($product['deletedAtUtc']) || empty($product['isActive'])) {
            return false;
        }
        if (strtolower((string) ($product['publicationStatus'] ?? '')) !== 'published') {
            return false;
        }
        $now = time();
        $publishAt = isset($product['publishAtUtc']) && $product['publishAtUtc'] !== null
            ? strtotime((string) $product['publishAtUtc']) : null;
        $unpublishAt = isset($product['unpublishAtUtc']) && $product['unpublishAtUtc'] !== null
            ? strtotime((string) $product['unpublishAtUtc']) : null;
        if ($publishAt !== null && $publishAt !== false && $publishAt > $now) {
            return false;
        }
        if ($unpublishAt !== null && $unpublishAt !== false && $unpublishAt <= $now) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, list<string>> $regionCodesByProductPublicId
     */
    private function isVisibleInRegion(string $productPublicId, ?string $regionCode, array $regionCodesByProductPublicId): bool
    {
        if ($regionCode === null || trim($regionCode) === '') {
            return true;
        }
        $codes = $regionCodesByProductPublicId[$productPublicId] ?? [];
        if ($codes === []) {
            return false;
        }
        $needle = strtoupper(trim($regionCode));

        return in_array($needle, $codes, true);
    }
}
