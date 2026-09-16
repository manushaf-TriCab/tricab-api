<?php

declare(strict_types=1);

namespace TriCab\Api\Technical\Document;

use TriCab\Api\Support\JsonValue;
use TriCab\Api\Technical\TechnicalContentRepository;

/**
 * Technical documents query adapter.
 *
 * catalog.json supplies family / IA configuration.
 * technical.Documents (via repository) supplies authoritative document records
 * when present. Empty library ⇒ catalog inventory items remain (pre-migration).
 */
final class ResolveTechnicalDocumentsQuery
{
    public function __construct(
        private readonly TechnicalContentRepository $content,
        private readonly TechnicalDocumentLibraryRepository $library,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(?string $category = null): array
    {
        $payload = $this->content->documents($category);
        $normalizedCategory = $category !== null ? trim($category) : '';

        if ($normalizedCategory !== '') {
            $libraryItems = $this->libraryItemsForCategory($normalizedCategory);
            if ($libraryItems !== []) {
                $payload['items'] = $libraryItems;
                $payload['itemSource'] = 'technical-document-library';
            } else {
                $payload['itemSource'] = 'catalog-json';
            }

            return $payload;
        }

        $families = $payload['families'] ?? null;
        if (!is_array($families)) {
            return $payload;
        }

        $merged = [];
        foreach ($families as $key => $familyRaw) {
            if (!is_string($key) || !is_array($familyRaw)) {
                continue;
            }
            $family = JsonValue::toObject($familyRaw);
            $categoryCode = JsonValue::string($family, 'documentCategory', $key);
            $libraryItems = $this->libraryItemsForCategory($categoryCode);
            if ($libraryItems !== []) {
                $family['items'] = $libraryItems;
                $family['itemCount'] = count($libraryItems);
                $family['itemSource'] = 'technical-document-library';
            } else {
                $family['itemSource'] = 'catalog-json';
            }
            $merged[$key] = $family;
        }
        $payload['families'] = $merged;
        $payload['architecture'] = 'Technical Document Library (technical.Documents) is authoritative for browsable documents. catalog.json retains family/IA configuration. product.ProductDocuments remain product-owned.';

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function libraryItemsForCategory(string $categoryCode): array
    {
        $records = $this->library->listActiveByCategory($categoryCode);
        $items = [];
        foreach ($records as $record) {
            $items[] = $record->toCatalogItem($categoryCode);
        }

        return $items;
    }
}
