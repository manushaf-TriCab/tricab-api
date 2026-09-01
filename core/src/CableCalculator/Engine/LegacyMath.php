<?php

declare(strict_types=1);

namespace TriCab\Core\CableCalculator\Engine;

/**
 * Legacy math.php routines ported without global $_POST access.
 */
final class LegacyMath
{
    /* inductance mH per km */
    public static function inductance(float $xa, float $od = 0, bool $multi = false, bool $isAl = false): float
    {
        if ($isAl) {
            $indAl = [
                0.5 => 0.502, 0.75 => 0.482, 1 => 0.447, 1.5 => 0.416, 2.5 => 0.407, 4 => 0.368,
                6 => 0.35, 10 => 0.319, 16 => 0.298, 25 => 0.287, 35 => 0.275, 50 => 0.264,
                70 => 0.26, 95 => 0.254, 120 => 0.252, 150 => 0.251, 185 => 0.249, 240 => 0.247,
                300 => 0.243, 400 => 0.241, 500 => 0.238, 630 => 0.236, 800 => 0.235, 1000 => 0.233,
            ];
            if (($indAl[$xa] ?? 0) > 0) {
                return $indAl[$xa];
            }
        }

        $inductance = [
            0.5 => 0.528, 0.75 => 0.507, 1 => 0.471, 1.5 => 0.438, 2.5 => 0.428, 4 => 0.387,
            6 => 0.368, 10 => 0.336, 16 => 0.314, 25 => 0.303, 35 => 0.288, 50 => 0.279,
            70 => 0.269, 95 => 0.262, 120 => 0.259, 150 => 0.258, 185 => 0.257, 240 => 0.254,
            300 => 0.25, 400 => 0.247, 500 => 0.246, 630 => 0.244, 800 => 0.215,
        ];

        $spacing = [
            1.5 => 2.997, 2.5 => 3.609, 4.0 => 4.017, 6.0 => 4.629, 10 => 5.649, 16 => 6.669,
            25 => 8.408, 35 => 9.836, 50 => 11.471, 70 => 13.310, 95 => 15.350, 120 => 17.290,
            150 => 19.540, 185 => 21.687, 240 => 24.239, 300 => 26.690, 400 => 30.673,
        ];

        $space = ($spacing[$xa] ?? 0) > 0 ? $spacing[$xa] : 0.07 * $xa;

        if ($od > 0) {
            $dia = self::dia($xa);
            $od = $multi ? $space * 0.9 : $od;

            return 0.2 * log(2 * $od / $dia) + 0.0514;
        }

        if (($inductance[$xa] ?? 0) > 0) {
            return $inductance[$xa];
        }

        return 0.0;
    }

    public static function reactance(
        float $xa,
        float $length,
        float $od,
        float|string|bool $freq = 50,
        bool $multi = false,
        bool $isAl = false,
    ): float {
        if ($freq == '') {
            $freq = 50;
        }

        return $length * 2 * pi() * (float) $freq * self::inductance($xa, $od, $multi, $isAl) / 1_000_000;
    }

    public static function rerate(float $rate, float $ambient, int $maxtemp, int $ratedTemp = 40): float
    {
        return round($rate * round(1 + ($ambient - $ratedTemp) / ($maxtemp === 110 ? 149 : 91), 2));
    }

    /** @param array<string, mixed> $cable */
    public static function earth(float $xa, int $runs, int $type, float|string $xae, array $cable): float
    {
        if (!$cable['is_al'] && $cable['is_alum_earth']) {
            $sizes = [1000 => 500, 800 => 400, 630 => 185, 500 => 185, 400 => 185, 300 => 185, 240 => 150, 185 => 120, 150 => 95, 120 => 70, 95 => 50, 70 => 50, 50 => 35, 35 => 25, 25 => 16, 16 => 16, 10 => 10, 6 => 6];
            $cf = 2;
        } elseif ($cable['is_al'] && !$cable['is_alum_earth']) {
            $sizes = [1000 => 185, 800 => 150, 630 => 120, 500 => 95, 400 => 95, 300 => 70, 240 => 50, 185 => 35, 150 => 25, 120 => 25, 95 => 16, 70 => 10, 50 => 10, 35 => 6, 25 => 6, 16 => 4, 10 => 4, 6 => 2.5];
            $cf = 6;
        } elseif ($cable['is_al'] && $cable['is_alum_earth']) {
            $sizes = [1000 => 300, 800 => 240, 630 => 185, 500 => 150, 400 => 150, 300 => 120, 240 => 95, 185 => 70, 150 => 50, 120 => 50, 95 => 35, 70 => 25, 50 => 25, 35 => 16, 25 => 10, 16 => 6, 10 => 4, 6 => 2.5];
            $cf = 3.33;
        } else {
            $sizes = [1000 => 300, 800 => 240, 630 => 120, 500 => 120, 400 => 120, 300 => 120, 240 => 95, 185 => 70, 150 => 50, 120 => 35, 95 => 25, 70 => 25, 50 => 16, 35 => 10, 25 => 6, 16 => 6, 10 => 4, 6 => 2.5];
            $cf = 4;
        }

        if (isset($cable['earth_ne']) && (int) $cable['earth_ne'] > 0) {
            $xa *= $runs / (int) $cable['earth_ne'];
        }

        if (!array_key_exists((int) $xa, $sizes) || (isset($cable['earth_ne']) && (int) $cable['earth_ne'] !== $runs)) {
            $minearth = $xa / $cf;

            if ($cable['is_alum_earth'] && $minearth <= 25) {
                return 25;
            }

            if ($minearth <= 10) {
                if ($minearth < 2.5) {
                    return 2.5;
                }
                if ($minearth < 4) {
                    return 4;
                }
                if ($minearth < 6) {
                    return 6;
                }

                return 10;
            }

            foreach ($sizes as $csa => $earth) {
                if ($minearth >= $csa) {
                    prev($sizes);
                    if (key($sizes) < $sizes[$cable['xa']]) {
                        return (float) $sizes[$cable['xa']];
                    }

                    return (float) key($sizes);
                }
                next($sizes);
            }
        }

        if ($xa <= 0) {
            return $xa;
        }
        if ($xa < 6) {
            return ($xa >= 2.5) ? 2.5 : $xa;
        }

        foreach ($sizes as $csa => $ea) {
            if ($xa >= $csa) {
                return (float) (($cable['is_alum_earth'] && $ea >= 25) ? $ea : ($cable['is_alum_earth'] ? 25 : $ea));
            }
        }

        return $xa;
    }

    public static function opTemp(float $ambient, float $load, float $maxLoad, int $rating): float
    {
        return $ambient + ($rating - $ambient) * pow($load / $maxLoad, 2);
    }

    public static function dcRes(float $xa, float $temp, float $length, bool $isAl = false): float
    {
        $rstvtBySize = [
            0.5 => [0.0195, 0.03], 0.75 => [0.0195, 0.03], 1 => [0.0195, 0.03], 1.5 => [0.01995, 0.03],
            2.5 => [0.01995, 0.03], 4 => [0.0198, 0.03], 6 => [0.0198, 0.03], 10 => [0.0191, 0.03],
            16 => [0.01936, 0.03], 25 => [0.0195, 0.03], 35 => [0.01939, 0.03038], 50 => [0.0193, 0.03205],
            70 => [0.01904, 0.03101], 95 => [0.01957, 0.0304], 120 => [0.01932, 0.03036], 150 => [0.01935, 0.0309],
            185 => [0.01961, 0.03034], 240 => [0.019224, 0.03], 300 => [0.01923, 0.03], 400 => [0.01944, 0.03112],
            500 => [0.0192, 0.03025],
        ];

        if (!$isAl) {
            $rstvt = 0.0191;
            if (array_key_exists($xa, $rstvtBySize)) {
                $rstvt = $rstvtBySize[$xa][0];
            }
        } else {
            $rstvt = 0.03;
            if (array_key_exists($xa, $rstvtBySize)) {
                $rstvt = $rstvtBySize[$xa][1];
            }
        }

        if ($xa != 0) {
            return ($rstvt * (1 + (0.00393 * ($temp - 20))) * $length) / $xa;
        }

        return 0.0;
    }

    public static function impedance(float $voltDrop, float $load, float $length): float|string
    {
        return $load ? ($voltDrop * 1000) / (sqrt(3) * $load * $length) : '';
    }

    /** @param array<string, mixed> $cableArray */
    public static function getFaultLoop(array $cableArray, float $length, CableCalculationRuntimeContext $context): float
    {
        $isAlumEarth = $context->earthAluminium ? 1 : 0;

        $xa = $cableArray['xa'];
        $isMulti = ($cableArray['type'] > 3);

        $dcResActive = self::dcRes((int) $xa, (float) $cableArray['ot'], $length, (bool) $cableArray['is_al']);
        $acResActive = self::acRes($dcResActive, self::skinEffect($dcResActive), self::proximity($xa, $dcResActive), (int) $cableArray['type']) / $cableArray['runs'];
        $reactActive = self::reactance($xa, $length, (float) $cableArray['od'], $isMulti, (bool) $cableArray['is_al']) / $cableArray['runs'];

        $dcResEarth = self::dcRes((int) $cableArray['earth_size'], (float) $cableArray['ambient'], $length, (bool) $isAlumEarth);
        if (isset($cableArray['earth_ne']) && (int) $cableArray['earth_ne'] !== 0) {
            $acResEarth = self::acRes($dcResEarth, self::skinEffect($dcResEarth), self::proximity((float) $cableArray['earth_size'], $dcResEarth), (int) $cableArray['type']) / (int) $cableArray['earth_ne'];
            $reactEarth = self::reactance((float) $cableArray['earth_size'], $length, (float) ($cableArray['earth_od'] ?? 0), false, (bool) $cableArray['is_al']) / (int) $cableArray['earth_ne'];
        } else {
            $acResEarth = 0;
            $reactEarth = 0;
        }

        $a = round($acResActive + $acResEarth, 4);
        $b = round($reactActive + $reactEarth, 4);

        $loopFault = 1000 * sqrt(pow($a, 2) + pow($b, 2));
        if ($loopFault < 1000) {
            $loopFault = substr((string) $loopFault, 0, 4);
        } else {
            $loopFault = round($loopFault, 1);
        }

        return round((float) $loopFault, 4);
    }

    /** @param array<string, mixed> $candArray */
    public static function calculateSpace(
        array $candArray,
        int $type,
        int $runs,
        string $install,
        CableCalculationRuntimeContext $context,
    ): float|string {
        if ($candArray['od'] === '' || $candArray['od'] === null) {
            $od = $candArray['width'];
        } else {
            $od = $candArray['od'];
        }

        $erh = $context->earthResistanceMode !== '' ? $context->earthResistanceMode : '1';

        if ($install !== 'buried' && $install !== 'buriedconduit' && $install !== 'conduit') {
            if ($type < 4) {
                return ceil(2 * (float) $od * (2 * $runs - 1));
            }

            return ceil((float) $od * $runs + ($runs - 1) * (float) $od);
        }

        $vstcn = [[16, 12.5], [20, 16.1], [25, 21.1], [32, 27.5], [40, 34.9], [50, 44.1], [63, 58.8], [80, 80], [100, 100], [125, 125], [150, 150], [200, 200]];
        $buried = ($install === 'buried' || $install === 'buriedconduit') ? 1.1 : 1;

        if ($type < 4) {
            $vspaceInitial = ceil((float) $od * (($erh === '0' || $erh === 0) ? 2.8 : 3.2) * $buried);
        } else {
            $vspaceInitial = ceil((float) $od * 1.3 * $buried);
        }

        $return = 'not found';
        for ($i = 0; $i < count($vstcn); $i++) {
            if (($vstcn[$i][1] - $vspaceInitial) > 0) {
                $return = $vstcn[$i][0];
                break;
            }
        }

        return $return;
    }

    public static function voltDrop(
        float $temp,
        float $xa,
        float $load,
        float $length,
        float $od = 0,
        float $voltage = 400,
        int|string $phase = '',
        int|string $type = '',
        float $pf = 0.85,
        bool $isAl = false,
    ): float {
        $isMulti = (($type > 3 && $type < 10) || $type > 12);
        $dcRes = self::dcRes($xa, $temp, $length, $isAl);
        $acRes = self::acRes($dcRes, self::skinEffect($dcRes), self::proximity($xa, $dcRes), (int) $type);
        $react = self::reactance($xa, $length, $od, 50, $isMulti, $isAl);
        $imp = self::zImp($acRes, $react, $pf);

        if ($phase == 5) {
            return round((2 * $load * $dcRes) / ($voltage / 100), 2);
        }
        if ($phase == 4) {
            return round(($load * 2 * $imp) / ($voltage / 100), 2);
        }

        return round(($load * sqrt(3) * $imp) / ($voltage / 100), 2);
    }

    public static function fieldRes(float $dcRes, float $freq = 50): float
    {
        if ($dcRes != 0) {
            return pow(((8 * pi() * $freq) / $dcRes) * pow(10, -7), 2);
        }

        return 0.0;
    }

    public static function skinEffect(float $dcRes, float $freq = 50): float
    {
        $skin = self::fieldRes($dcRes, $freq);

        return $skin / (192 + 0.8 * $skin);
    }

    public static function dia(float $xa): float
    {
        return 2 * sqrt($xa / pi());
    }

    public static function proximity(float $xa, float $dcRes, float $gap = 0, float $freq = 50): float
    {
        if ($xa === 0.0) {
            return 0.0;
        }

        $dia = self::dia($xa);
        $prox = self::fieldRes($dcRes, $freq);
        if ($gap == 0) {
            $gap = 2 * $dia;
        }
        $field = pow($dia / $gap, 2);

        return $prox / (192 + (0.8 * $prox)) * $field * (0.312 * $field + 1.18 / ($field / (192 + (0.8 * $field)) + 0.27));
    }

    public static function acRes(float $dcRes, float $skin, float $prox, int $type = 0): float
    {
        $multiplier = $type > 3 ? 1.02 : 1;

        return $multiplier * $dcRes * (1 + $skin + $prox);
    }

    public static function zImp(float $acRes, float $react, float $pf = 0.85): float
    {
        $pfRad = acos($pf);

        return $acRes * cos($pfRad) + $react * sin($pfRad);
    }

    /**
     * @param array<string, mixed> $cable
     * @param array<string, list<float>> $kconstantAr
     * @return array{active: float, earth: float}
     */
    public static function calcFaultRating(
        array $cable,
        array $kconstantAr,
        CableCalculationRuntimeContext $context,
        int $opTemp = 90,
    ): array {
        $cableSize = $cable['xa'];
        $cableStyle = $cable['cable_style'];
        $earthStyle = $cable['earth_style'];
        $earthSize = (($cable['type'] > 3 && $cable['type'] < 10) || $cable['type'] > 12 ? $cable['xae'] : $cable['earth_size']);

        if ($cable['type'] != 1 && $cable['type'] != 4 && $cable['type'] != 9) {
            $opTemp = 110;
        }

        $isAlumEarth = $context->earthAluminium ? 1 : 0;
        $time = $context->maximumFaultTimeSeconds !== 0.0 ? $context->maximumFaultTimeSeconds : 1.0;
        if ($time == 0) {
            $time = 1;
        }

        $erh = $context->earthResistanceMode;
        if ($erh !== '' && $erh !== 'auto') {
            $numberEarth = (int) $erh;
        } elseif (($cable['type'] < 4 || ($cable['type'] > 9 && $cable['type'] < 13)) && (int) $cable['ne'] === 0 && $cableStyle !== '') {
            $numberEarth = 1;
        } else {
            $numberEarth = (int) $cable['ne'];
        }

        $kActive = self::getLookupFaultConstant($opTemp, (bool) $cable['is_al'], $kconstantAr, $cableStyle);
        $active = round($cable['runs'] * $kActive * $cableSize / (1000 * sqrt($time)), 3);

        $kEarth = self::getLookupFaultConstant((int) $cable['ambient'], (bool) $isAlumEarth, $kconstantAr, $earthStyle);
        $earth = round($numberEarth * $kEarth * $earthSize / (1000 * sqrt($time)), 3);

        return ['active' => $active, 'earth' => $earth];
    }

    /** @param array<string, list<float>> $kconstantAr */
    public static function getLookupFaultConstant(int $opTemp, bool $isAlumin, array $kconstantAr, string $cableStyle): float
    {
        $indexAr = $isAlumin ? [2, 3] : [0, 1];
        $index = $cableStyle === 'LP' ? $indexAr[0] : $indexAr[1];
        $return = 0.0;

        if (array_key_exists((string) $opTemp, $kconstantAr)) {
            $return = $kconstantAr[(string) $opTemp][$index];
        } else {
            for ($i = $opTemp; $i < 7; $i++) {
                if (array_key_exists((string) $i, $kconstantAr)) {
                    $return = $kconstantAr[(string) $i][$index];
                }
            }
        }

        return $return;
    }
}
