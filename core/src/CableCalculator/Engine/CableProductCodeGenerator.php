<?php

declare(strict_types=1);

namespace TriCab\Core\CableCalculator\Engine;

final class CableProductCodeGenerator
{
    public static function generate(
        string $style,
        float|string $size,
        bool $url = false,
        int|string $core = 1,
        int $earth = 0,
        int $aluminChecked = 0,
        bool|string|int $ctAlumin = false,
        string $colour = '',
        float|string $earthSize = '',
    ): string {
        $style = str_replace("('", '', $style);
        $style = str_replace("')", '', $style);

        $config = 'c';
        if (!is_numeric($core)) {
            $parts = preg_split('/([a-z])/i', (string) $core, -1, PREG_SPLIT_DELIM_CAPTURE);
            $core = $parts[0] ?? '1';
            $config = $parts[1] ?? 'c';
        }

        $size = preg_replace('/0\./', '.', (string) $size);

        if ($style === 'LP' || $style === 'LS' || $style === 'UP') {
            $core = '1' . $config;
        } else {
            $core = ((int) $core + (($earth > 0 && $style !== 'OD') ? 1 : 0)) . $config;
        }

        if ($aluminChecked === 1 || $ctAlumin) {
            $coreColours = ($earthSize == $size) ? 'P9XA' : 'PAXA';
        } elseif (in_array($style, ['BV', 'DV', 'PJ'], true)) {
            $coreColours = 'PAXD';
        } elseif ($style === 'OD') {
            $coreColours = 'PDTN';
        } elseif ($style === 'KK') {
            $coreColours = 'PDXN';
        } elseif (in_array($style, ['KU', 'XU'], true)) {
            $coreColours = 'PAZN';
        } else {
            $coreColours = 'PAXN';
        }

        $cableColour = [
            'LS' => '', 'LP' => '', 'FR' => 'RE', 'FH' => 'RE', 'BV' => 'BK', 'DV' => 'BK', 'PJ' => 'BK',
            'KK' => 'BK', 'KB' => 'BK', 'SD' => 'BK', 'SF' => 'BK', 'OD' => 'BK', 'KU' => 'BK', 'XU' => 'BK',
        ];
        if (array_key_exists($style, $cableColour) && $colour !== 'EA') {
            $colour = $cableColour[$style];
        }

        $productCode = $style . '-' . $coreColours . '/' . strtoupper((string) $core) . $size . $colour;

        if (str_contains($productCode, '/1C') && !in_array($style, ['CC', 'KL', 'XL', 'FR', 'BV', 'DV', 'PJ'], true)) {
            $productCode = str_replace('PAX', 'XXX', $productCode);
        }

        if ((float) $size <= 6) {
            $productCode = str_replace('XXXN/', 'XXXD/', $productCode);
        }

        if ($url) {
            throw new \LogicException('Product code URLs are not supported in the native engine.');
        }

        return $productCode;
    }
}
