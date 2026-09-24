<?php

declare(strict_types=1);

namespace TriCab\Api\PdfCatalogue\Persistence;

use TriCab\Core\PdfCatalogue\Application\DTO\PdfCatalogueItem;
use TriCab\Core\PdfCatalogue\Contract\PdfCatalogueRepository;
use TriCab\Core\PdfCatalogue\Domain\PdfCatalogueInventory;

/**
 * Deterministic in-memory catalogue inventory for local/CI.
 * Documents are marked unavailable until blob migration marks them available.
 */
final class InMemoryPdfCatalogueRepository implements PdfCatalogueRepository
{
    /** @var list<PdfCatalogueItem> */
    private array $items;

    /** @var array<string, true> */
    private array $inactiveSeedKeys;

    /**
     * @param array<string, bool> $availabilityBySeedKey seedKey => document available
     * @param list<string> $inactiveSeedKeys seed keys excluded from active list/detail/file
     */
    public function __construct(array $availabilityBySeedKey = [], array $inactiveSeedKeys = [])
    {
        $inactive = [];
        foreach ($inactiveSeedKeys as $seedKey) {
            $inactive[strtolower(trim($seedKey))] = true;
        }
        $this->inactiveSeedKeys = $inactive;

        $items = [];
        foreach (PdfCatalogueInventory::definitions() as $def) {
            $seedKey = $def['seedKey'];
            $available = $availabilityBySeedKey[$seedKey] ?? false;
            $items[] = new PdfCatalogueItem(
                publicId: self::deterministicPublicId($seedKey),
                title: $def['title'],
                issue: $def['issue'],
                regions: $def['regions'],
                sortOrder: $def['sortOrder'],
                available: $available,
                seedKey: $seedKey,
                documentBlobPath: $def['documentBlobPath'],
                fileName: basename(str_replace('\\', '/', $def['legacyRelativePath'])),
            );
        }
        usort(
            $items,
            static fn (PdfCatalogueItem $a, PdfCatalogueItem $b): int =>
                [$a->sortOrder, $a->title] <=> [$b->sortOrder, $b->title],
        );
        $this->items = $items;
    }

    public function listActive(): array
    {
        return array_values(array_filter(
            $this->items,
            fn (PdfCatalogueItem $item): bool => $this->isActiveSeed($item->seedKey),
        ));
    }

    public function findActiveByPublicId(string $publicId): ?PdfCatalogueItem
    {
        foreach ($this->items as $item) {
            if ($item->publicId === $publicId && $this->isActiveSeed($item->seedKey)) {
                return $item;
            }
        }

        return null;
    }

    private function isActiveSeed(string $seedKey): bool
    {
        return !isset($this->inactiveSeedKeys[strtolower(trim($seedKey))]);
    }

    public static function deterministicPublicId(string $seedKey): string
    {
        // Stable UUID v5-like hex derived from seed key for tests and memory mode.
        $hash = md5('tricab-pdf-catalogue:' . $seedKey);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            '4' . substr($hash, 13, 3),
            'a' . substr($hash, 17, 3),
            substr($hash, 20, 12),
        );
    }
}
