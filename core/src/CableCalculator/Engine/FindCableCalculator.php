<?php

declare(strict_types=1);

namespace TriCab\Core\CableCalculator\Engine;

use TriCab\Core\CableCalculator\Persistence\JsonCableRatingRepository;
use TriCab\Core\CableCalculator\Persistence\JsonCableSpecificationRepository;

final class FindCableCalculator
{
    /** @var array<string, list<float>> */
    private const FAULT_CONSTANT = [
        '130' => [63.6, 121, 42, 79.6],
        '125' => [68.90, 123, 45.5, 81.5],
        '110' => [83.2, 132, 55, 87.1],
        '90' => [99.9, 143, 66, 94.5],
        '85' => [104, 146, 68.6, 96.3],
        '80' => [108, 149, 71.1, 98.1],
        '75' => [111, 151, 73.6, 99.9],
        '70' => [115, 154, 76, 102],
        '65' => [119, 157, 78.4, 104],
        '60' => [122, 159, 80.8, 105],
        '55' => [126, 162, 83.1, 107],
        '50' => [129, 165, 85.5, 109],
        '45' => [133, 168, 87.7, 111],
        '40' => [136, 170, 90, 113],
        '35' => [140, 173, 92.3, 114],
        '30' => [143, 176, 94.5, 116],
        '25' => [146, 179, 96.8, 118],
    ];

    /** @var list<float> */
    private const DERATING_BURIED_CONDUIT = [1, 1, 0.9, 0.83, 0.79, 0.75, 0.73, 0.71, 0.7, 0.68];

    /** @var list<float> */
    private const DERATING_SINGLE = [1, 1, 0.78, 0.66, 0.61, 0.56, 0.53, 0.50, 0.49, 0.47, 0.46, 0.44, 0.43];

    /** @var list<float> */
    private const DERATING_MULTI = [1, 1, 0.81, 0.70, 0.63, 0.59, 0.55, 0.52, 0.50, 0.48, 0.47, 0.45, 0.44];

    public function __construct(
        private readonly JsonCableRatingRepository $ratings,
        private readonly JsonCableSpecificationRepository $specifications,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findCable(
        string|array $style,
        float $cndtvt,
        float|string $impedance,
        float $length,
        float|string $load,
        int $vambient,
        int $install,
        int $maxtemp,
        float $maxvd,
        int $minruns,
        float $voltage,
        int|string $phase,
        int|string $type,
        int|string $operating,
        float $vpowerFactor,
        int $runs,
        string $earthStyle,
        bool $isAl,
        float|string $acablesize,
        int $maxOpTemp,
        bool $isAlumEarth,
        int $maxRuns,
        CableCalculationRuntimeContext $context,
    ): ?array {
        $vload = $load;
        if ($maxOpTemp === 0) {
            $maxOpTemp = $maxtemp;
        }

        $minrunsOption = null;
        $runsUpperlimit = null;
        if ($minruns > 2) {
            $runs = $minruns - 2;
            $minrunsOption = $minruns;
            $runsUpperlimit = $minruns - 2;
            $minruns = 1;
        }

        $ambient = $vambient >= 35 ? 40 : 30;
        $installItem = $install;

        switch ($install) {
            case 1:
                $installName = 'spaced';
                break;
            case 2:
                $installName = 'touch';
                break;
            case 3:
                $installName = 'buriedconduit';
                $ambient = $vambient === 15 ? 30 : 40;
                break;
            case 4:
                $installName = 'buried';
                $ambient = $vambient === 15 ? 30 : 40;
                break;
            case 5:
                $installName = 'conduit';
                break;
            case 6:
                $installName = 'sun';
                break;
            case 7:
                $installName = 'buriedconduit';
                $ambient = $vambient === 15 ? 30 : 40;
                break;
            case 8:
                $installName = 'iec';
                $ambient = 45;
                break;
            case 9:
                $installName = 'buried';
                $ambient = $vambient === 15 ? 30 : 40;
                break;
            default:
                $installName = 'spaced';
        }

        $opTemp = (float) $ambient;
        $type = (int) $type;
        $phase = (int) $phase;
        $operating = (int) $operating;

        [$configTb, $cores, $coreCount] = $this->resolveCoreConfiguration($type, $phase);

        $styles = CableStyleResolver::parseStyleSql($style);
        $styleSql = is_array($style) ? (string) ($style[0] ?? '') : (string) $style;
        $candidates = [];
        $candidateArray = null;

        while ($runs > 0) {
            $matchingCable = $this->queryMatchingCables(
                $styles,
                $cores,
                $coreCount,
                $configTb,
                $ambient,
                (int) $operating,
                $isAl,
                $installName,
                $load,
                $runs,
                $acablesize,
                $type,
                $phase,
            );

            foreach ($matchingCable as $candidateCable) {
                $candidateCable = $this->applyInstallationDerating($candidateCable, $installItem, $runs);

                if ($load === '') {
                    $load = $candidateCable['current'];
                }

                $candidateCable['ot'] = LegacyMath::opTemp(
                    (float) $vambient,
                    (float) $load / $runs,
                    (float) $candidateCable['current'],
                    $maxtemp,
                );

                if ($candidateCable['ot'] > $maxOpTemp || $candidateCable['ot'] > $operating) {
                    continue;
                }

                $candidateCable['vd'] = LegacyMath::voltDrop(
                    (float) $candidateCable['ot'],
                    (float) $candidateCable['xa'],
                    (float) $load / $runs,
                    $length,
                    (float) $candidateCable['od'],
                    $voltage,
                    $phase,
                    $type,
                    $vpowerFactor,
                    $isAl,
                );

                $rerateAmbient = $opTemp;
                if ($installName === 'buriedconduit' || $installName === 'buried') {
                    if ($opTemp == 40) {
                        $rerateAmbient = 25;
                    } elseif ($opTemp == 30) {
                        $rerateAmbient = 15;
                    }
                }

                $candidateCable['current'] = LegacyMath::rerate(
                    (float) $candidateCable['current'],
                    $rerateAmbient,
                    $maxtemp,
                    $vambient,
                );
                $candidateCable['space'] = LegacyMath::calculateSpace($candidateCable, $type, $runs, $installName, $context);

                if ($candidateCable['vd'] <= $maxvd && ($candidateCable['current'] * $runs > $load)) {
                    $resolvedEarthStyle = (($type > 3 && $type < 9) || $type === 0) ? $styleSql : $earthStyle;

                    $candidateArray = [
                        'type' => $type,
                        'phase' => $phase,
                        'maxtemp' => $maxtemp,
                        'length' => $length,
                        'cable_style' => $styleSql,
                        'pf' => $vpowerFactor,
                        'earth_style' => $resolvedEarthStyle,
                        'is_al' => $isAl ? 1 : 0,
                        'is_alum_earth' => $isAlumEarth ? 1 : 0,
                        'nc' => $candidateCable['nc'],
                        'xa' => (float) $candidateCable['xa'],
                        'ne' => $candidateCable['ne'],
                        'xae' => round((float) $candidateCable['xae'], 1),
                        'cr' => $candidateCable['current'] * $runs,
                        'od' => $candidateCable['od'],
                        'space' => $candidateCable['space'],
                        'style' => $candidateCable['cable_style'],
                        'ot' => $candidateCable['ot'],
                        'vd' => $candidateCable['vd'],
                        'runs' => $runs,
                        'qty' => $runs * 4 * $length,
                        'txa' => $runs * (float) $candidateCable['xa'],
                        'cable_weight' => $candidateCable['wght'],
                        'ambient' => $candidateCable['ambient'],
                    ];

                    $candidateArray = CableDetailsGenerator::generate(
                        $candidateArray,
                        self::FAULT_CONSTANT,
                        $operating,
                        $context,
                    );
                    $candidates[] = $candidateArray;

                    if ($minruns != 1) {
                        if ($acablesize === 'N/A' || $acablesize === '') {
                            return $candidates[0];
                        }
                        if ($candidateArray['xa'] == $acablesize) {
                            return $candidateArray;
                        }
                        if ($candidateArray['xa'] == $acablesize && $vload === '' && $runs === 1) {
                            return $candidateArray;
                        }
                    }
                }
            }

            if ((++$runs > $maxRuns) || ($minrunsOption > 2 && ++$runs > $runsUpperlimit)) {
                $runs = 0;
            }
        }

        if ($candidates === []) {
            return null;
        }

        $lowest = 0;
        foreach ($candidates as $key => $cand) {
            if ($cand['txa'] <= $candidates[$lowest]['txa']) {
                $lowest = $key;
            }
        }

        if ($acablesize === 'N/A' || $acablesize === '') {
            return $candidates[$lowest];
        }

        if ($candidateArray !== null && $candidateArray['xa'] == $acablesize) {
            return $candidateArray;
        }

        if (
            $candidateArray !== null
            && $candidateArray['xa'] == $acablesize
            && $vload === ''
            && $runs === 1
            && $length != 0
        ) {
            return $candidateArray;
        }

        return $candidates[$lowest];
    }

    /**
     * @return array{0: string, 1: int, 2: int}
     */
    private function resolveCoreConfiguration(int $type, int $phase): array
    {
        if ($type > 3) {
            if ($phase === 5) {
                $configTb = 'Single';
                $cores = 2;
            } else {
                $configTb = 'Multi';
            }

            $cores = $phase === 3 ? 4 : 2;
            $coreCount = $phase > 3 ? 2 : 3;

            if ($type === 9) {
                $cores = 3;
            }
            if ($type === 7) {
                $cores = 3;
            }
            if ($phase === 5 && $type === 8) {
                $cores = 4;
            }
            if ($type >= 10) {
                if ($type < 13) {
                    $cores = 1;
                } elseif ($phase === 5) {
                    $cores = 2;
                } elseif ($phase === 4) {
                    $cores = 3;
                } else {
                    $cores = 5;
                }
            }
        } else {
            $configTb = 'Single';
            $cores = 1;
            $coreCount = $phase > 3 ? 2 : ($phase === 3 ? 3 : 2);
        }

        return [$configTb, $cores, $coreCount];
    }

    /**
     * @param list<string> $styles
     * @return list<array<string, mixed>>
     */
    private function queryMatchingCables(
        array $styles,
        int $cores,
        int $coreCount,
        string $configTb,
        int $ambient,
        int $operating,
        bool $isAl,
        string $installName,
        float|string $load,
        int $runs,
        float|string $acablesize,
        int $type,
        int $phase,
    ): array {
        $specRows = $this->specifications->findByStylesAndCores($styles, $cores, $isAl);
        $matches = [];

        foreach ($specRows as $spec) {
            $size = JsonCableRatingRepository::normalizeSize((string) $spec['xa']);
            $rating = $this->ratings->findByJoinKey($size, $ambient, $operating, $configTb, $coreCount, $isAl, false);
            if ($rating === null) {
                continue;
            }

            if ($type === 4 && $operating === 90 && ((string) ($rating['config'] ?? '') !== $configTb || (int) ($rating['corecount'] ?? 0) !== $coreCount || (int) ($rating['operating'] ?? 0) !== 90)) {
                continue;
            }

            $current = (float) ($rating[$installName] ?? 0);
            if ($type > 9 && $type < 13 && $phase === 5) {
                $dcRating = $this->ratings->findByJoinKey($size, $ambient, $operating, $configTb, $coreCount, $isAl, true);
                $iec = $dcRating['iec'] ?? '';
                $current = $iec !== '' && $iec !== null ? (float) $iec : (float) ($rating['iec'] ?? $current);
            }

            if ($load !== '' && !($acablesize > 0) && $current < ((float) $load / $runs)) {
                continue;
            }

            $matches[] = [
                'xa' => $spec['xa'],
                'xae' => $spec['xae'],
                'cable_style' => $spec['cable_style'],
                'wght' => $spec['wght'],
                'nc' => $spec['nc'],
                'ne' => $spec['ne'],
                'ambient' => $rating['ambient'],
                'current' => $current,
                'od' => $spec['od'],
                'width' => $spec['width'],
            ];
        }

        return $matches;
    }

    /** @param array<string, mixed> $candidateCable */
    private function applyInstallationDerating(array $candidateCable, int $installItem, int $runs): array
    {
        if ($installItem === 7) {
            if ($runs < 10) {
                $candidateCable['current'] = self::DERATING_BURIED_CONDUIT[$runs] * $candidateCable['current'];
            } else {
                $candidateCable['current'] = (pow(1.0072 * $runs, -0.1780)) * $candidateCable['current'];
            }
        } elseif ($installItem === 9) {
            if ($runs < 13) {
                $table = ((int) $candidateCable['nc'] === 1) ? self::DERATING_SINGLE : self::DERATING_MULTI;
                $candidateCable['current'] = $table[$runs] * $candidateCable['current'];
            } else {
                $candidateCable['current'] = ((int) $candidateCable['nc'] === 1 ? 0.43 : 0.44) * $candidateCable['current'];
            }
        } elseif ($installItem === 8 && $runs > 6) {
            $candidateCable['current'] = $candidateCable['current'] * 0.85;
        }

        return $candidateCable;
    }
}
