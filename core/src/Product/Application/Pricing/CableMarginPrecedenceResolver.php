<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Pricing;

/**
 * Pure margin-rule selection matching ProductConfigurator::evaluateCableMargin precedence.
 *
 * Levels (most specific first): termkt → ter → mkt → ct
 * Style preference: with-style before no-style (stylg=0)
 * Core class: sdi (ncond=1) / multi (ncond=5) / control (ncond=6) / nocore (ncond=0|null)
 *
 * @phpstan-type MarginRow array{
 *   withStyle: bool,
 *   level: 'termkt'|'ter'|'mkt'|'ct',
 *   ncond: int,
 *   marginPercent: string
 * }
 */
final class CableMarginPrecedenceResolver
{
    public const PT_CABLE = 1615;
    public const NCOND_SDI = 1;
    public const NCOND_MULTI = 5;
    public const NCOND_CONTROL = 6;
    public const NCOND_ANY = 0;

    /**
     * @param list<MarginRow> $rows
     * @return array{marginPercent: string, ruleLevel: string, coreClass: string}|null
     */
    public function resolve(array $rows, int $coreCount, string $colourConfigPrefix = ''): ?array
    {
        $coreClass = $this->coreClass($coreCount, $colourConfigPrefix);
        $targetNcond = match ($coreClass) {
            'sdi' => self::NCOND_SDI,
            'multi' => self::NCOND_MULTI,
            'control' => self::NCOND_CONTROL,
            default => self::NCOND_ANY,
        };

        $pick = $this->pickForNcond($rows, $targetNcond, true)
            ?? $this->pickForNcond($rows, self::NCOND_ANY, true)
            ?? $this->pickForNcond($rows, $targetNcond, false)
            ?? $this->pickForNcond($rows, self::NCOND_ANY, false);

        if ($pick === null) {
            return null;
        }

        return [
            'marginPercent' => $pick['marginPercent'],
            'ruleLevel' => ($pick['withStyle'] ? '' : 'ns_') . $pick['level'],
            'coreClass' => $coreClass,
        ];
    }

    public function coreClass(int $coreCount, string $colourConfigPrefix = ''): string
    {
        if ($coreCount === 1) {
            return 'sdi';
        }
        $prefix = strtoupper(substr(trim($colourConfigPrefix), 0, 1));
        if ($prefix === 'P' || $prefix === 'X') {
            return 'multi';
        }

        return 'control';
    }

    /**
     * @param list<MarginRow> $rows
     * @return MarginRow|null
     */
    private function pickForNcond(array $rows, int $ncond, bool $withStyle): ?array
    {
        $levels = ['termkt', 'ter', 'mkt', 'ct'];
        foreach ($levels as $level) {
            foreach ($rows as $row) {
                if ($row['withStyle'] !== $withStyle) {
                    continue;
                }
                if ($row['level'] !== $level) {
                    continue;
                }
                $rowNcond = (int) $row['ncond'];
                if ($ncond === self::NCOND_ANY) {
                    if ($rowNcond !== 0) {
                        continue;
                    }
                } elseif ($rowNcond !== $ncond) {
                    continue;
                }

                return $row;
            }
        }

        return null;
    }
}
