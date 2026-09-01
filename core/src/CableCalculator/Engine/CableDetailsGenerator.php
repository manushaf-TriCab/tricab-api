<?php

declare(strict_types=1);

namespace TriCab\Core\CableCalculator\Engine;

final class CableDetailsGenerator
{
    /** @param array<string, list<float>> $faultConstant */
    public static function generate(
        array $cable,
        array $faultConstant,
        int $opTemp,
        CableCalculationRuntimeContext $context,
    ): array {
        $isMulti = $cable['type'] > 3;

        $xa = (int) $cable['xa'];
        $cable['dc_res'] = LegacyMath::dcRes($xa, (float) $cable['ot'], (float) $cable['length'], (bool) $cable['is_al']);
        $cable['ac_res'] = LegacyMath::acRes($cable['dc_res'], LegacyMath::skinEffect($cable['dc_res']), LegacyMath::proximity($xa, $cable['dc_res']), (int) $cable['type']);
        $cable['react'] = LegacyMath::reactance($xa, (float) $cable['length'], (float) $cable['od'], 50, $isMulti);
        $cable['z_imp'] = LegacyMath::zImp($cable['ac_res'], $cable['react'], (float) $cable['pf']);

        $cable['price'] = null;
        $cable['weight'] = $cable['runs'] * ($cable['type'] < 4 ? 4 : 1) * (float) $cable['cable_weight'] / 1000;

        $erh = $context->earthResistanceMode;
        $cable['request_earth_ne'] = $erh;
        if ($erh === 'auto') {
            $cable['earth_ne'] = $cable['runs'];
        } elseif ($erh !== '') {
            $cable['earth_ne'] = $erh;
        } elseif ($cable['type'] > 9 && $cable['type'] < 13) {
            $cable['earth_ne'] = $cable['runs'];
        } elseif ($cable['type'] < 4 && $cable['phase'] < 5) {
            $cable['earth_ne'] = 1;
        } elseif ($cable['type'] < 4 && $cable['phase'] == 5) {
            $cable['earth_ne'] = 0;
        } elseif ($cable['type'] > 3) {
            $cable['earth_ne'] = $cable['ne'];
        }

        if (isset($cable['earth_ne']) && $cable['xa'] * $cable['runs'] / $cable['earth_ne'] > 1000) {
            $cable['earth_ne'] = (int) ceil($cable['xa'] * $cable['runs'] / 1000);
        }

        $cable['earth_size'] = LegacyMath::earth((float) $cable['xa'], (int) $cable['runs'], (int) $cable['type'], $cable['xae'], $cable);

        if ($cable['type'] != 1 && $cable['type'] != 4 && $cable['type'] == 9) {
            $maxTemp = 110;
        } else {
            $maxTemp = $opTemp;
        }

        $return = LegacyMath::calcFaultRating($cable, $faultConstant, $context, $maxTemp);

        $cable['fr-active'] = round($return['active'] !== 0.0 ? $return['active'] : 0.0, 2);
        $cable['fr-earth'] = round($return['earth'] !== 0.0 ? $return['earth'] : 0.0, 2);
        $cable['fault-loop'] = round(LegacyMath::getFaultLoop($cable, (float) $cable['length'], $context), 3);

        return $cable;
    }
}
