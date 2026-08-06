<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\Service;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\ValueObject\ProductCodeValue;

/**
 * Assembles a product code from ordered segments (domain-only; no persistence).
 *
 * @phpstan-type CodeSegment array{
 *   order: int,
 *   value: string,
 *   required: bool,
 *   separatorAfter?: ?string
 * }
 */
final class ProductCodeSegmentAssembler
{
    /**
     * @param list<CodeSegment> $segments
     */
    public function assemble(array $segments): ProductCodeValue
    {
        if ($segments === []) {
            throw InvalidProductValue::for('ProductCodeSegments', 'empty');
        }

        usort(
            $segments,
            static fn (array $a, array $b): int => $a['order'] <=> $b['order'],
        );

        $orders = array_map(static fn (array $s): int => $s['order'], $segments);
        if (count($orders) !== count(array_unique($orders))) {
            throw InvalidProductValue::for('ProductCodeSegments', 'duplicate-order');
        }

        $included = [];
        foreach ($segments as $segment) {
            $value = trim($segment['value']);
            if ($value === '') {
                if ($segment['required']) {
                    throw InvalidProductValue::for('ProductCodeSegment', 'required-missing');
                }
                continue;
            }
            $included[] = $segment;
        }

        if ($included === []) {
            throw InvalidProductValue::for('ProductCodeValue', 'empty');
        }

        $assembled = '';
        foreach ($included as $i => $segment) {
            $assembled .= trim($segment['value']);
            if ($i < count($included) - 1) {
                $assembled .= (string) ($segment['separatorAfter'] ?? '');
            }
        }

        return ProductCodeValue::fromString($assembled);
    }

    /**
     * @param list<CodeSegment> $segments
     */
    public function explain(array $segments): string
    {
        usort(
            $segments,
            static fn (array $a, array $b): int => $a['order'] <=> $b['order'],
        );

        $bits = [];
        foreach ($segments as $segment) {
            $value = trim($segment['value']);
            if ($value === '') {
                continue;
            }
            $bits[] = $value;
        }

        return implode(' + ', $bits);
    }
}
