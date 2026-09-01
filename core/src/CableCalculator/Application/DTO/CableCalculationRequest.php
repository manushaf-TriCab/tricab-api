<?php

declare(strict_types=1);

namespace TriCab\Core\CableCalculator\Application\DTO;

/**
 * Legacy TriCalc input semantics. Load is always amperes.
 */
final readonly class CableCalculationRequest
{
    public function __construct(
        public string $reference,
        public float $loadAmps,
        public float $runLengthM,
        public float $powerFactor,
        public float $maximumVoltageDropPercent,
        public int $voltage,
        public int $phaseClass,
        public int $ambientTemperatureC,
        public int $maxOperatingTemperatureC,
        public string $activeConductorMaterial,
        public string $earthConductorMaterial,
        public int $cableType,
        public int $installationMethod,
        public int $cableRunsMode,
        public ?string $fixedCableSize,
        public string $earthResistanceMode,
        public float $maximumFaultTimeSeconds,
        public string $compare,
        public string $compareWith,
    ) {
    }

    /** @return array<string, mixed> Legacy replay-lib input shape */
    public function toLegacyInput(): array
    {
        $activeAl = strtolower($this->activeConductorMaterial) === 'al' || strtolower($this->activeConductorMaterial) === 'aluminium';
        $earthAl = strtolower($this->earthConductorMaterial) === 'al' || strtolower($this->earthConductorMaterial) === 'aluminium';

        return [
            'reference' => $this->reference,
            'load' => $this->loadAmps,
            'length' => $this->runLengthM,
            'pf' => $this->powerFactor,
            'maxvd' => $this->maximumVoltageDropPercent,
            'voltage' => $this->voltage,
            'phase' => $this->phaseClass,
            'ambient' => $this->ambientTemperatureC,
            'max_op_temp' => $this->maxOperatingTemperatureC,
            'cabletype' => $this->cableType,
            'type' => $this->cableType,
            'installation' => $this->installationMethod,
            'minruns' => $this->cableRunsMode,
            'activeConductor' => $activeAl ? 'Al' : 'Cu',
            'earthConductor' => $earthAl ? 'Al' : 'Cu',
            'Erh' => $this->earthResistanceMode,
            'mftime' => $this->maximumFaultTimeSeconds,
            'acablesize' => $this->fixedCableSize ?? 'N/A',
            'compare' => $this->compare,
            'compare_with' => $this->compareWith,
        ];
    }
}
