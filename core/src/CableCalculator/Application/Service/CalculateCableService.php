<?php

declare(strict_types=1);

namespace TriCab\Core\CableCalculator\Application\Service;

use TriCab\Core\CableCalculator\Application\DTO\CableCalculationRequest;
use TriCab\Core\CableCalculator\Application\DTO\CableCalculationResult;
use TriCab\Core\CableCalculator\Contract\CableCalculatorEngine;

final class CalculateCableService
{
    public function __construct(
        private readonly CableCalculatorEngine $engine,
    ) {
    }

    public function calculate(CableCalculationRequest $request, bool $isPreview = false): CableCalculationResult
    {
        CableCalculationRequestValidator::validate($request);

        return $this->engine->calculate($request, $isPreview);
    }
}
