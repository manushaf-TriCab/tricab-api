<?php

declare(strict_types=1);

namespace TriCab\Core\CableCalculator\Application\Service;

use TriCab\Core\CableCalculator\Application\DTO\CableCalculationRequest;
use TriCab\Core\CableCalculator\Application\Exception\CableCalculationValidationException;

final class CableCalculationRequestValidator
{
    public static function validate(CableCalculationRequest $request): void
    {
        $errors = [];

        if ($request->loadAmps <= 0 || $request->loadAmps > 6500) {
            $errors['loadAmps'] = 'Load must be between 1 and 6500 amperes.';
        }
        if ($request->runLengthM <= 0) {
            $errors['runLengthM'] = 'Run length must be greater than zero.';
        }
        if ($request->powerFactor < 0.7 || $request->powerFactor > 1.0) {
            $errors['powerFactor'] = 'Power factor must be between 0.70 and 1.00.';
        }
        if ($request->maximumVoltageDropPercent <= 0 || $request->maximumVoltageDropPercent > 7) {
            $errors['maximumVoltageDropPercent'] = 'Maximum voltage drop must be between 0.1% and 7%.';
        }
        if ($request->phaseClass < 3 || $request->phaseClass > 5) {
            $errors['phaseClass'] = 'Invalid phase class.';
        }
        if ($request->cableType < 0 || $request->cableType > 15) {
            $errors['cableType'] = 'Invalid cable type.';
        }
        if ($request->installationMethod < 1 || $request->installationMethod > 9) {
            $errors['installationMethod'] = 'Invalid installation method.';
        }
        if ($request->cableRunsMode < 1 || $request->cableRunsMode > 102) {
            $errors['cableRunsMode'] = 'Invalid cable runs mode.';
        }
        if ($request->earthResistanceMode === '0') {
            $errors['earthResistanceMode'] = 'Erh=0 is not supported (legacy division-by-zero).';
        }

        if ($errors !== []) {
            throw CableCalculationValidationException::withFieldErrors($errors);
        }
    }
}
