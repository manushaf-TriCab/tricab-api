<?php

declare(strict_types=1);

namespace TriCab\Core\CableCalculator\Engine;

use TriCab\Core\CableCalculator\Application\DTO\CableCalculationRequest;

final class ProductionCableCalculator
{
    public function __construct(
        private readonly FindCableCalculator $findCableCalculator,
    ) {
    }

    /**
     * @return array{
     *     mode: string,
     *     result90: array<string, mixed>|null,
     *     result110: array<string, mixed>|null
     * }
     */
    public function calculate(CableCalculationRequest $request): array
    {
        $activeAl = strtolower($request->activeConductorMaterial) === 'al' || strtolower($request->activeConductorMaterial) === 'aluminium';
        $earthAl = strtolower($request->earthConductorMaterial) === 'al' || strtolower($request->earthConductorMaterial) === 'aluminium';

        $load = $request->loadAmps;
        $length = $request->runLengthM;
        $acablesize = $request->fixedCableSize ?? 'N/A';
        $type = $request->cableType;
        $phase = $request->phaseClass;
        $ambient = $request->ambientTemperatureC;
        $maxOpTemp = $request->maxOperatingTemperatureC;
        $maxvd = $request->maximumVoltageDropPercent;
        $maxRuns = 100;

        $operating1 = 90;
        $operating2 = 110;
        $maxvd1 = $maxvd;
        $maxvd2 = $maxvd * 1.1;

        $voltDrop = $maxvd * $request->voltage / 100;
        $impedance = LegacyMath::impedance($voltDrop, $load, $length);

        $context = CableCalculationRuntimeContext::fromRequest(
            $request->earthResistanceMode,
            $request->maximumFaultTimeSeconds,
            $earthAl,
        );

        [$cableStyles, $earthStyle] = CableStyleResolver::suggestCableStyle($type, $operating1, $phase);
        $earthStyle = CableStyleResolver::buriedAluminiumEarthStyle($earthStyle, $type, $earthAl, $request->installationMethod);

        $cable = $this->findCableCalculator->findCable(
            $cableStyles,
            26.5,
            $impedance,
            $length,
            $load,
            $ambient,
            $request->installationMethod,
            $operating1,
            $maxvd1,
            $request->cableRunsMode,
            (float) $request->voltage,
            $phase,
            $type,
            $operating1,
            $request->powerFactor,
            1,
            $earthStyle,
            $activeAl,
            $acablesize,
            $maxOpTemp,
            $earthAl,
            $maxRuns,
            $context,
        );

        $cable110 = null;
        if ($type === 1 || $type === 4 || $type === 9 || $type === 0) {
            [$cableStyles110, $earthStyle110] = CableStyleResolver::suggestCableStyle($type, $operating2, $phase);
            $earthStyle110 = CableStyleResolver::buriedAluminiumEarthStyle($earthStyle110, $type, $earthAl, $request->installationMethod);
            $cable110 = $this->findCableCalculator->findCable(
                $cableStyles110,
                26.5,
                $impedance,
                $length,
                $load,
                $ambient,
                $request->installationMethod,
                $operating2,
                $maxvd2,
                $request->cableRunsMode,
                (float) $request->voltage,
                $phase,
                $type,
                $operating2,
                $request->powerFactor,
                1,
                $earthStyle110,
                $activeAl,
                $acablesize,
                $maxOpTemp,
                $earthAl,
                $maxRuns,
                $context,
            );
        } elseif ($cable !== null) {
            $cable110 = $cable;
            $cable = null;
        }

        return [
            'mode' => 'production',
            'result90' => self::extractCableOutput(is_array($cable) ? $cable : null, $type, $phase, $earthAl),
            'result110' => self::extractCableOutput(is_array($cable110) ? $cable110 : null, $type, $phase, $earthAl),
        ];
    }

    /** @return array<string, mixed>|null */
    public static function extractCableOutput(?array $cable, int $type, int $phase, bool $earthAl): ?array
    {
        if (!$cable || !isset($cable['xa']) || !$cable['xa']) {
            return null;
        }

        $multiply = ($type > 3) ? 1 : (($phase === 4 || $phase === 5) ? 2 : 4);

        $activeCode = CableProductCodeGenerator::generate(
            (string) $cable['style'],
            $cable['xa'],
            false,
            $cable['nc'],
            $cable['ne'],
            0,
            (int) ($cable['is_al'] ?? 0),
            $cable['is_al'] ? 'BK' : 'OR',
            $cable['xae'] ?? '',
        );

        $earthCode = '';
        if (!empty($cable['earth_style']) && !empty($cable['earth_size'])) {
            $earthCode = CableProductCodeGenerator::generate(
                (string) $cable['earth_style'],
                $cable['earth_size'],
                false,
                $cable['earth_ne'] ?? 1,
                $cable['ne'] ?? 0,
                0,
                $cable['is_alum_earth'] ?? 0,
                'EA',
                $cable['xae'] ?? '',
            );
        }

        return [
            'style' => $cable['style'],
            'xa' => (float) $cable['xa'],
            'earth_size' => isset($cable['earth_size']) ? (float) $cable['earth_size'] : null,
            'xae' => isset($cable['xae']) ? (float) $cable['xae'] : null,
            'runs' => (int) $cable['runs'],
            'cr' => isset($cable['cr']) ? (float) $cable['cr'] : null,
            'ot' => isset($cable['ot']) ? (int) round((float) $cable['ot']) : null,
            'vd' => isset($cable['vd']) ? (float) $cable['vd'] : null,
            'fault-loop' => isset($cable['fault-loop']) ? (float) $cable['fault-loop'] : null,
            'fr-active' => isset($cable['fr-active']) ? (float) $cable['fr-active'] : null,
            'fr-earth' => isset($cable['fr-earth']) ? (float) $cable['fr-earth'] : null,
            'space' => $cable['space'] ?? null,
            'weight' => isset($cable['weight']) ? (float) $cable['weight'] : null,
            'qty' => isset($cable['qty']) ? (float) $cable['qty'] : null,
            'multiple' => (int) $cable['runs'] * $multiply,
            'productCodeActive' => $activeCode,
            'productCodeEarth' => $earthCode,
            'nc' => $cable['nc'] ?? null,
            'ne' => $cable['ne'] ?? null,
            'earth_ne' => $cable['earth_ne'] ?? null,
        ];
    }
}
