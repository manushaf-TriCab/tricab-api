<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Contract;

/**
 * Strictly read-only WordPress product inspection API. No save/update/delete.
 */
interface LegacyWordPressRepository
{
    /**
     * @param array<string, mixed> $filter
     * @return list<array<string, mixed>>
     */
    public function findProducts(array $filter = []): array;

    /**
     * @param list<int|string> $postIds
     * @return list<array<string, mixed>>
     */
    public function findAttachments(array $postIds): array;

    /**
     * @param list<int|string> $postIds
     * @return list<array<string, mixed>>
     */
    public function findCategories(array $postIds): array;

    /**
     * @param list<int|string> $postIds
     * @return list<array<string, mixed>>
     */
    public function findSpecifications(array $postIds): array;

    /**
     * @param list<int|string> $postIds
     * @return list<array<string, mixed>>
     */
    public function findRelationships(array $postIds): array;

    /**
     * @return array{tables: list<string>, columns: array<string, list<string>>, productCounts: array<string, int>}
     */
    public function inspectSchema(): array;
}
