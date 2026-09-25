<?php

declare(strict_types=1);

namespace TriCab\Api\DocumentGeneration\Rendering;

use TriCab\Core\DocumentGeneration\Legacy\LegacyRecord;

/**
 * Deterministic quote item page chunks for Dompdf.
 *
 * Dompdf does not close table borders when a single large table splits across
 * pages, and it can leave TOTAL/Conductor alone on a final page. This allocator
 * partitions items into per-page table segments sized to fit, then applies an
 * orphan rule so a totals page always inherits at least one complete item when
 * a separate final page is required.
 */
final class QuoteItemPageAllocator
{
    /** Usable body height after @page margins (mm). */
    private const BODY_HEIGHT_MM = 297.0 - QuoteDocumentPagination::TOP_MARGIN_MM - QuoteDocumentPagination::BOTTOM_MARGIN_MM;

    /** Page-1 chrome above the items table (logo, address/meta, comments). */
    private const PAGE1_OVERHEAD_MM = 112.0;

    /** Repeated thead height on every item table segment. */
    private const THEAD_HEIGHT_MM = 8.0;

    /** Conservative height for a typical 2-line product row. */
    private const ITEM_BASE_HEIGHT_MM = 10.2;

    /** Extra height per item comment line. */
    private const ITEM_COMMENT_HEIGHT_MM = 4.2;

    /** Extra height when description/code is long enough to wrap. */
    private const ITEM_WRAP_EXTRA_MM = 3.6;

    private const TOTALS_HEIGHT_MM = 11.0;

    private const CONDUCTOR_HEIGHT_MM = 36.0;

    /** Slack so Dompdf layout variance does not push closing alone. */
    private const CLOSING_SLACK_MM = 6.0;

    /**
     * @param list<array{record: LegacyRecord, comments: list<LegacyRecord>}> $items
     * @return list<list<array{record: LegacyRecord, comments: list<LegacyRecord>}>>
     */
    public static function allocate(array $items, bool $hasConductorComparison): array
    {
        if ($items === []) {
            return [[]];
        }

        $closingHeight = self::TOTALS_HEIGHT_MM
            + ($hasConductorComparison ? self::CONDUCTOR_HEIGHT_MM : 0.0)
            + self::CLOSING_SLACK_MM;
        $page1Capacity = max(20.0, self::BODY_HEIGHT_MM - self::PAGE1_OVERHEAD_MM - self::THEAD_HEIGHT_MM);
        $contCapacity = max(40.0, self::BODY_HEIGHT_MM - self::THEAD_HEIGHT_MM);

        $chunks = [];
        $remaining = $items;
        $isFirst = true;

        while ($remaining !== []) {
            $capacity = $isFirst ? $page1Capacity : $contCapacity;
            $chunk = [];
            $used = 0.0;

            while ($remaining !== []) {
                $next = $remaining[0];
                $h = self::estimateItemHeightMm($next);
                if ($chunk !== [] && ($used + $h) > $capacity) {
                    break;
                }
                // Always take at least one item even if taller than capacity (pathological).
                if ($chunk !== [] && $h > $capacity) {
                    break;
                }
                $chunk[] = array_shift($remaining);
                $used += $h;
                if ($h > $capacity && count($chunk) === 1) {
                    break;
                }
            }

            $chunks[] = $chunk;
            $isFirst = false;
        }

        return self::applyOrphanRule($chunks, $closingHeight, $page1Capacity, $contCapacity);
    }

    /**
     * If closing cannot fit under the last item chunk, open a new final page and
     * move >= 1 complete item onto it. Never invent an extra page when closing
     * already fits with the last items.
     *
     * @param list<list<array{record: LegacyRecord, comments: list<LegacyRecord>}>> $chunks
     * @return list<list<array{record: LegacyRecord, comments: list<LegacyRecord>}>>
     */
    private static function applyOrphanRule(
        array $chunks,
        float $closingHeight,
        float $page1Capacity,
        float $contCapacity,
    ): array {
        if ($chunks === []) {
            return [[]];
        }

        $lastIndex = count($chunks) - 1;
        $lastCapacity = $lastIndex === 0 ? $page1Capacity : $contCapacity;
        $lastUsed = self::estimateChunkHeightMm($chunks[$lastIndex]);

        if (($lastUsed + $closingHeight) <= $lastCapacity) {
            return $chunks;
        }

        // Closing needs its own page — pull complete items from the previous chunk.
        $donorIndex = $lastIndex;
        $pull = [];
        $pullHeight = 0.0;
        $finalCapacity = $contCapacity;

        while ($chunks[$donorIndex] !== []) {
            $item = array_pop($chunks[$donorIndex]);
            $h = self::estimateItemHeightMm($item);
            array_unshift($pull, $item);
            $pullHeight += $h;

            if ($pullHeight + $closingHeight <= $finalCapacity) {
                break;
            }

            // Pathological: even one item + closing exceeds a page — keep one item anyway.
            if (count($pull) === 1 && $h + $closingHeight > $finalCapacity) {
                break;
            }

            if ($chunks[$donorIndex] === [] && $donorIndex > 0) {
                // Donor emptied; continue pulling from earlier page if still too tall.
                unset($chunks[$donorIndex]);
                $chunks = array_values($chunks);
                $donorIndex = count($chunks) - 1;
                if ($donorIndex < 0) {
                    break;
                }
            }
        }

        // Drop any empty donor chunk left behind.
        if ($chunks !== [] && $chunks[count($chunks) - 1] === []) {
            array_pop($chunks);
        }

        $chunks[] = $pull;

        return array_values(array_filter($chunks, static fn (array $chunk): bool => $chunk !== []));
    }

    /**
     * @param array{record: LegacyRecord, comments: list<LegacyRecord>} $itemData
     */
    public static function estimateItemHeightMm(array $itemData): float
    {
        $record = $itemData['record'];
        $desc = trim($record->field(8));
        $code = trim($record->field(2) . ' ' . $record->field(3));
        $comments = $itemData['comments'];

        $height = self::ITEM_BASE_HEIGHT_MM;
        if (strlen($desc) > 42 || strlen($code) > 42) {
            $height += self::ITEM_WRAP_EXTRA_MM;
        }
        $height += count($comments) * self::ITEM_COMMENT_HEIGHT_MM;

        return $height;
    }

    /**
     * @param list<array{record: LegacyRecord, comments: list<LegacyRecord>}> $chunk
     */
    private static function estimateChunkHeightMm(array $chunk): float
    {
        $total = 0.0;
        foreach ($chunk as $item) {
            $total += self::estimateItemHeightMm($item);
        }

        return $total;
    }
}
