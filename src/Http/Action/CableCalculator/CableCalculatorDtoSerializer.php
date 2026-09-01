<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\CableCalculator;

use TriCab\Core\CableCalculator\Application\DTO\CableCalculationBand;
use TriCab\Core\CableCalculator\Application\DTO\CableCalculationRequest;
use TriCab\Core\CableCalculator\Application\DTO\CableCalculationResult;
use TriCab\Core\CableCalculator\Application\Presentation\CableCalculationPresenter;
use TriCab\Core\CableCalculator\Application\ProductResolution\CalculatedProductReference;

final class CableCalculatorDtoSerializer
{
  /** @return array<string, mixed> */
  public static function request(CableCalculationRequest $request): array
  {
    return [
      'reference' => $request->reference,
      'loadAmps' => $request->loadAmps,
      'runLengthM' => $request->runLengthM,
      'powerFactor' => $request->powerFactor,
      'maximumVoltageDropPercent' => $request->maximumVoltageDropPercent,
      'voltage' => $request->voltage,
      'phaseClass' => $request->phaseClass,
      'ambientTemperatureC' => $request->ambientTemperatureC,
      'maxOperatingTemperatureC' => $request->maxOperatingTemperatureC,
      'activeConductorMaterial' => $request->activeConductorMaterial,
      'earthConductorMaterial' => $request->earthConductorMaterial,
      'cableType' => $request->cableType,
      'installationMethod' => $request->installationMethod,
      'cableRunsMode' => $request->cableRunsMode,
      'fixedCableSize' => $request->fixedCableSize,
      'earthResistanceMode' => $request->earthResistanceMode,
      'maximumFaultTimeSeconds' => $request->maximumFaultTimeSeconds,
      'compare' => $request->compare,
      'compareWith' => $request->compareWith,
    ];
  }

  /** @return array<string, mixed> */
  public static function bandRaw(CableCalculationBand $band): array
  {
    return [
      'temperatureBand' => $band->temperatureBand,
      'style' => $band->style,
      'activeSizeMm2' => $band->activeSizeMm2,
      'earthSizeMm2' => $band->earthSizeMm2,
      'earthScreenSizeMm2' => $band->earthScreenSizeMm2,
      'runs' => $band->runs,
      'currentRatingA' => $band->currentRatingA,
      'operatingTemperatureC' => $band->operatingTemperatureC,
      'voltageDropPercent' => $band->voltageDropPercent,
      'faultLoop' => $band->faultLoop,
      'faultRatingActive' => $band->faultRatingActive,
      'faultRatingEarth' => $band->faultRatingEarth,
      'spaceRequirement' => $band->spaceRequirement,
      'weightKg' => $band->weightKg,
      'quantityM' => $band->quantityM,
      'multiple' => $band->multiple,
      'productCodeActive' => $band->productCodeActive,
      'productCodeEarth' => $band->productCodeEarth,
      'nc' => $band->nc,
      'ne' => $band->ne,
      'earthNe' => $band->earthNe,
    ];
  }

  /**
   * @param list<CalculatedProductReference> $productResolution
   * @return array<string, mixed>
   */
  public static function result(CableCalculationResult $result, array $productResolution = []): array
  {
    $display = CableCalculationPresenter::present($result);
    $rawBands = array_map(self::bandRaw(...), $result->bands);
    $primaryRaw = $result->primaryBand() ? self::bandRaw($result->primaryBand()) : null;

    $resolutionPayload = array_map(
      static fn (CalculatedProductReference $ref): array => $ref->toArray(),
      $productResolution,
    );

    return [
      'calculationStatus' => 'COMPLETE',
      'request' => self::request($result->request),
      'bands' => $rawBands,
      'display' => $display,
      'warnings' => $result->warnings,
      'isPreview' => $result->isPreview,
      'calculatedAtUtc' => $result->calculatedAtUtc,
      'engineVersion' => strtolower((string) (\TriCab\Api\Bootstrap\Environment::value('CABLE_CALCULATOR_ENGINE', 'legacy-cli') ?? 'legacy-cli')) === 'native'
        ? 'native-v1'
        : 'legacy-cli-oracle',
      'datasetVersion' => 'legacy-tc-2026-08',
      'parityBaseline' => $result->parityBaseline,
      'primaryBand' => $primaryRaw,
      'voltageDropVolts' => $display['voltageDropVolts'],
      'statusLabel' => 'CALCULATION COMPLETE',
      'productResolution' => $resolutionPayload,
    ];
  }
}
