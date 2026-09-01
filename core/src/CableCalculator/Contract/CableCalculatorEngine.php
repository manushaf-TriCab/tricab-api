<?php

declare(strict_types=1);

namespace TriCab\Core\CableCalculator\Contract;

use TriCab\Core\CableCalculator\Application\DTO\CableCalculationRequest;
use TriCab\Core\CableCalculator\Application\DTO\CableCalculationResult;

interface CableCalculatorEngine
{
    public function calculate(CableCalculationRequest $request, bool $isPreview = false): CableCalculationResult;
}
