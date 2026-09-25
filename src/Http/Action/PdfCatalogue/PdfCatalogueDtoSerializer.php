<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\PdfCatalogue;

use TriCab\Core\PdfCatalogue\Application\DTO\PdfCatalogueItem;

final class PdfCatalogueDtoSerializer
{
    /**
     * @return array{
     *   id: string,
     *   title: string,
     *   issue: string,
     *   regions: list<string>,
     *   sortOrder: int,
     *   available: bool
     * }
     */
    public static function item(PdfCatalogueItem $item): array
    {
        return [
            'id' => $item->publicId,
            'title' => $item->title,
            'issue' => $item->issue,
            'regions' => $item->regions,
            'sortOrder' => $item->sortOrder,
            'available' => $item->available,
        ];
    }

    /**
     * @param list<PdfCatalogueItem> $items
     * @return array{items: list<array<string, mixed>>, count: int}
     */
    public static function list(array $items): array
    {
        return [
            'items' => array_map(self::item(...), $items),
            'count' => count($items),
        ];
    }
}
