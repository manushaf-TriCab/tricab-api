<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Classification;

/**
 * Comparison-only name normalization. Never mutates source values used for persistence.
 */
final class ClassificationNameNormalizer
{
    public function normalize(string $raw): string
    {
        $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = str_replace(["\u{00A0}", "\u{2007}", "\u{202F}"], ' ', $decoded);
        $decoded = strtr($decoded, [
            '–' => '-',
            '—' => '-',
            '−' => '-',
            '＆' => '&',
            '/' => ' ',
        ]);
        $decoded = str_ireplace('&', ' and ', $decoded);
        $decoded = preg_replace('/\s+/u', ' ', trim($decoded)) ?? '';
        $decoded = mb_strtolower($decoded, 'UTF-8');
        $decoded = preg_replace('/[^a-z0-9 ]+/u', '', $decoded) ?? '';

        return preg_replace('/\s+/u', ' ', trim($decoded)) ?? '';
    }
}
