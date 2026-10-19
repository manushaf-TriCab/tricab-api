<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Catalogue;

/**
 * Public card subtitle for a catalogue node.
 *
 * A saved node description always wins. A leaf holding exactly one visible
 * product falls back to that product's name, unless it would just repeat the
 * node name.
 */
final class CatalogueLeafDescription
{
    public static function resolve(?string $description, ?string $singleProductName, string $nodeName): ?string
    {
        $description = trim((string) $description);
        if ($description !== '') {
            return $description;
        }
        $productName = trim((string) $singleProductName);
        if ($productName === '' || strcasecmp($productName, trim($nodeName)) === 0) {
            return null;
        }

        return $productName;
    }
}
