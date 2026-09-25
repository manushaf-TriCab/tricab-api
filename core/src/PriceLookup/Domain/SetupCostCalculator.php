<?php

declare(strict_types=1);

namespace TriCab\Core\PriceLookup\Domain;

/**
 * Port of calculateSetupCost from cost-per-meter-calculation.php (simplified, faithful for MAKE+length).
 */
final class SetupCostCalculator
{
    /** @var list<string> */
    private const BED_CODES = [
        'B1', 'B3', 'B4', 'C3', 'C6', 'CA', 'CN', 'FE', 'FK', 'FZ', 'H7', 'HT',
        'IA', 'KA', 'KE', 'KG', 'KH', 'EA', 'M3', 'M4', 'M5', 'M6',
        'NF', 'NJ', 'NT', 'NU', 'OD', 'PJ', 'R2', 'R4', 'R6', 'R8',
        'RA', 'RB', 'S2', 'S3', 'S4', 'S6', 'XA',
    ];

    /** @var list<string> */
    private const ARM_CODES = [
        'SA', 'KA', 'CA', 'R2', 'R4', 'R6', 'R8', 'RA', 'IA', 'EA',
        'C3', 'C6', 'S2', 'S3', 'S4', 'S6',
    ];

    /** @var list<string> */
    private const NYLON_CODES = [
        'LN', 'XN', 'KN', 'CN', 'XDT', 'KDT', 'CDT',
    ];

    /** @var list<string> */
    private const EXTRUSION_CODES = [
        'V1', 'V2', 'V3', 'V4', 'V6', 'T4', 'P3', 'P4',
    ];

    /**
     * @return numeric-string
     */
    public function calculate(string $productCode): string
    {
        $parsed = $this->parseProductCode($productCode);
        if ($parsed === null) {
            return '0';
        }

        $result = 86 + 320;

        $firstTwoLetters = $parsed['segment_1'];
        $thirdLetter = $parsed['third_position'];
        $afterSlash = $parsed['after_separator'];

        if (in_array($firstTwoLetters, self::BED_CODES, true)) {
            $result += 300;
        }

        if ($thirdLetter !== 'X' && $thirdLetter !== '2' && $thirdLetter !== '3') {
            $result += 105;
        }

        if (in_array($firstTwoLetters, self::ARM_CODES, true)) {
            $result += 400;
        }

        if (in_array($firstTwoLetters, self::NYLON_CODES, true)) {
            $result += 300;
        }

        if (in_array($firstTwoLetters, self::EXTRUSION_CODES, true)) {
            $result += 1000;
        }

        $result += $this->sixthCondition($afterSlash);

        return (string) $result;
    }

    /**
     * @return array{
     *     segment_1: string,
     *     third_position: string,
     *     after_separator: string
     * }|null
     */
    private function parseProductCode(string $productCode): ?array
    {
        $pattern = '/^([A-Z0-9]{2})([A-Z0-9]{0,3})-([A-Z0-9]{2})([A-Z0-9])([A-Z0-9]{1,2})[\/-]([A-Z0-9.]+)$/';

        if (preg_match('/^[A-Z0-9]{2,4}-A/', $productCode) === 1) {
            return null;
        }

        if (preg_match($pattern, $productCode, $matches) !== 1) {
            return null;
        }

        return [
            'segment_1' => $matches[1],
            'third_position' => $matches[4],
            'after_separator' => $matches[6],
        ];
    }

    private function sixthCondition(string $afterSlash): int
    {
        if (preg_match('/^1C/', $afterSlash) === 1) {
            return 0;
        }

        if (preg_match('/^([0-9]{1,2})([CPTQ])/', $afterSlash, $matches) !== 1) {
            return 0;
        }

        $firstValue = (int) $matches[1];
        $secondLetter = $matches[2];
        $finalResult = $firstValue * 25;

        if ($finalResult <= 145) {
            $finalResult = 145;
        }

        if ($secondLetter === 'P' || $secondLetter === 'T' || $secondLetter === 'Q') {
            $finalResult += $firstValue * 100;
        }

        return $finalResult;
    }
}
