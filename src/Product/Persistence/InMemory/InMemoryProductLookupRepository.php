<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence\InMemory;

use TriCab\Core\Product\Application\DTO\ProductLookupItem;
use TriCab\Core\Product\Contract\ProductLookupRepository;

final class InMemoryProductLookupRepository implements ProductLookupRepository
{
    public function __construct(
        private readonly InMemoryProductStore $store,
    ) {
    }

    public function loadAdminLookups(): array
    {
        return [
            'statuses' => $this->listStatuses(),
            'lifecycleStatuses' => $this->listLifecycleStatuses(),
            'families' => $this->mapLookup($this->store->families()),
            'categories' => $this->mapLookup($this->store->categories()),
            'series' => $this->mapLookup($this->store->series()),
            'regions' => $this->mapLookup($this->store->regions()),
            'markets' => $this->mapLookup($this->store->markets()),
            'industries' => $this->mapLookup($this->store->industries()),
            'applications' => $this->mapLookup($this->store->applications()),
            'mediaTypes' => $this->listMediaTypes(),
            'documentTypes' => $this->listDocumentTypes(),
            'currencies' => $this->listCurrencies(),
            'specificationDefinitions' => $this->mapLookup($this->store->specDefinitions()),
            'codeRuleSets' => $this->mapLookup($this->store->ruleSets()),
        ];
    }

    public function listStatuses(): array
    {
        return $this->mapLookup($this->store->statuses());
    }

    public function listLifecycleStatuses(): array
    {
        return $this->mapLookup($this->store->lifecycleStatuses());
    }

    public function listMediaTypes(): array
    {
        return $this->mapLookup($this->store->mediaTypes());
    }

    public function listDocumentTypes(): array
    {
        return $this->mapLookup($this->store->documentTypes());
    }

    public function listCurrencies(): array
    {
        return $this->mapLookup($this->store->currencies());
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<ProductLookupItem>
     */
    private function mapLookup(array $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            if (($row['deleted'] ?? false) === true || !($row['isActive'] ?? true)) {
                continue;
            }
            $items[] = $this->toLookupItem($row);
        }

        usort(
            $items,
            static fn (ProductLookupItem $a, ProductLookupItem $b): int => $a->displayOrder <=> $b->displayOrder
                ?: strcmp($a->name, $b->name),
        );

        return $items;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toLookupItem(array $row): ProductLookupItem
    {
        $publicId = $row['publicId'] ?? '';
        $code = $row['code'] ?? $row['definitionCode'] ?? $row['ruleSetCode'] ?? '';
        $name = $row['name'] ?? $row['definitionName'] ?? $row['ruleSetName'] ?? '';
        $parentPublicId = $row['parentPublicId'] ?? null;

        return new ProductLookupItem(
            publicId: is_string($publicId) ? $publicId : '',
            code: is_string($code) ? $code : '',
            name: is_string($name) ? $name : '',
            parentPublicId: is_string($parentPublicId) ? $parentPublicId : null,
            displayOrder: $this->intValue($row, 'displayOrder'),
            isActive: (bool) ($row['isActive'] ?? true),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function intValue(array $row, string $key): int
    {
        $value = $row[$key] ?? 0;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }
}
