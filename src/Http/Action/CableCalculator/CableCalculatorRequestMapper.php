<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\CableCalculator;

use TriCab\Core\CableCalculator\Application\DTO\CableCalculationRequest;

final class CableCalculatorRequestMapper
{
  /** @param array<string, mixed> $body */
  public static function fromHttpBody(array $body): CableCalculationRequest
  {
    $fixed = self::stringOrNull($body, 'fixedCableSize') ?? self::stringOrNull($body, 'cableSize');
    if ($fixed === 'N/A' || $fixed === '' || $fixed === null) {
      $fixed = null;
    }

    return new CableCalculationRequest(
      reference: self::stringOrNull($body, 'reference') ?? '',
      loadAmps: self::float($body, 'loadAmps'),
      runLengthM: self::float($body, 'runLengthM'),
      powerFactor: self::float($body, 'powerFactor', 0.85),
      maximumVoltageDropPercent: self::float($body, 'maximumVoltageDropPercent', self::float($body, 'voltDropPercent', 5.0)),
      voltage: self::int($body, 'voltage', 400),
      phaseClass: self::int($body, 'phaseClass', 3),
      ambientTemperatureC: self::int($body, 'ambientTemperatureC', self::int($body, 'ambientC', 40)),
      maxOperatingTemperatureC: self::int(
        $body,
        'maxOperatingTemperatureC',
        self::int($body, 'maxOperatingTempC', self::int($body, 'maxOpTempC', 90)),
      ),
      activeConductorMaterial: self::normalizeMaterial(self::stringOrNull($body, 'activeConductorMaterial') ?? self::stringOrNull($body, 'activeConductor') ?? 'copper'),
      earthConductorMaterial: self::normalizeMaterial(self::stringOrNull($body, 'earthConductorMaterial') ?? self::stringOrNull($body, 'earthConductor') ?? 'copper'),
      cableType: self::int($body, 'cableType', self::int($body, 'selectedCable', 1)),
      installationMethod: self::int($body, 'installationMethod', self::int($body, 'installation', 1)),
      cableRunsMode: self::mapCableRunsMode($body),
      fixedCableSize: $fixed,
      earthResistanceMode: self::stringOrNull($body, 'earthResistanceMode') ?? self::stringOrNull($body, 'Erh') ?? 'auto',
      maximumFaultTimeSeconds: self::float($body, 'maximumFaultTimeSeconds', self::float($body, 'mftime', 1.0)),
      compare: self::stringOrNull($body, 'compare') ?? '0',
      compareWith: self::stringOrNull($body, 'compareWith') ?? '0',
    );
  }

  /** @param array<string, mixed> $body */
  private static function mapCableRunsMode(array $body): int
  {
    if (isset($body['cableRunsMode'])) {
      return self::int($body, 'cableRunsMode', 2);
    }

    $runs = self::stringOrNull($body, 'cableRuns');
    if ($runs === null || $runs === '') {
      return 2;
    }

    $numeric = (int) $runs;
    if ($numeric <= 0) {
      return 2;
    }

    return $numeric;
  }

  private static function normalizeMaterial(string $value): string
  {
    $lower = strtolower(trim($value));
    if ($lower === 'al' || $lower === 'aluminium' || $lower === 'aluminum' || $lower === 'aluminuim') {
      return 'aluminium';
    }

    return 'copper';
  }

  /** @param array<string, mixed> $body */
  private static function stringOrNull(array $body, string $key): ?string
  {
    if (!array_key_exists($key, $body)) {
      return null;
    }

    $value = $body[$key];
    if ($value === null || $value === '') {
      return null;
    }

    return (string) $value;
  }

  /** @param array<string, mixed> $body */
  private static function float(array $body, string $key, ?float $default = null): float
  {
    if (!array_key_exists($key, $body) || $body[$key] === '' || $body[$key] === null) {
      if ($default !== null) {
        return $default;
      }

      throw new \InvalidArgumentException("Missing numeric field: {$key}");
    }

    return (float) $body[$key];
  }

  /** @param array<string, mixed> $body */
  private static function int(array $body, string $key, ?int $default = null): int
  {
    if (!array_key_exists($key, $body) || $body[$key] === '' || $body[$key] === null) {
      if ($default !== null) {
        return $default;
      }

      throw new \InvalidArgumentException("Missing integer field: {$key}");
    }

    return (int) $body[$key];
  }
}
