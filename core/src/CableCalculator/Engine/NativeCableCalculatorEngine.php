<?php

declare(strict_types=1);

namespace TriCab\Core\CableCalculator\Engine;

use TriCab\Core\CableCalculator\Application\DTO\CableCalculationRequest;
use TriCab\Core\CableCalculator\Application\DTO\CableCalculationResult;
use TriCab\Core\CableCalculator\Application\Exception\CableCalculationValidationException;
use TriCab\Core\CableCalculator\Contract\CableCalculatorEngine;
use TriCab\Core\CableCalculator\Persistence\JsonCableRatingRepository;
use TriCab\Core\CableCalculator\Persistence\JsonCableSpecificationRepository;
use TriCab\Core\CableCalculator\Resources\CableCalculatorDatasetManifest;

final class NativeCableCalculatorEngine implements CableCalculatorEngine
{
    private readonly ProductionCableCalculator $productionCalculator;

    public function __construct(string $datasetDirectory)
    {
        $manifest = CableCalculatorDatasetManifest::fromDirectory($datasetDirectory);
        $ratings = new JsonCableRatingRepository($datasetDirectory . '/ratings.json');
        $specifications = new JsonCableSpecificationRepository($datasetDirectory . '/cableSpecs.json');
        $this->productionCalculator = new ProductionCableCalculator(
            new FindCableCalculator($ratings, $specifications),
        );

        if ($manifest->ratingsRowCount() !== count($ratings->all()) || $manifest->specsRowCount() !== count($specifications->all())) {
            throw new \RuntimeException('Cable calculator dataset row counts do not match manifest metadata.');
        }
    }

    public function calculate(CableCalculationRequest $request, bool $isPreview = false): CableCalculationResult
    {
        $output = $this->productionCalculator->calculate($request);

        if (empty($output['result90']) && empty($output['result110'])) {
            throw CableCalculationValidationException::withFieldErrors([
                'loadAmps' => 'No suitable cable size found for the given inputs.',
            ]);
        }

        $result = CableCalculationResult::fromLegacyOutput($request, $output, $isPreview);
        return new CableCalculationResult(
            request: $result->request,
            bands: $result->bands,
            warnings: ['Native PHP cable calculator engine (Phase 4).'],
            isPreview: $result->isPreview,
            calculatedAtUtc: $result->calculatedAtUtc,
            parityBaseline: 'LOCAL_LEGACY_PARITY_BASELINE',
        );
    }
}
