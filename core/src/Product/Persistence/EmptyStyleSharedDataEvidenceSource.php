<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Persistence;

use TriCab\Core\Product\Contract\StyleSharedDataEvidenceSource;

/** In-memory empty evidence source for tests / non-Azure environments. */
final class EmptyStyleSharedDataEvidenceSource implements StyleSharedDataEvidenceSource
{
    public function listPersistedFamilies(): array
    {
        return [];
    }

    public function listStyleAssignments(): array
    {
        return [];
    }

    public function loadSpecificationsForProducts(array $productCodes): array
    {
        return [];
    }

    public function loadMediaForProducts(array $productCodes): array
    {
        return [];
    }

    public function loadRegionsForProducts(array $productCodes): array
    {
        return [];
    }

    public function loadResourceCountsForProducts(array $productCodes): array
    {
        return [];
    }

    public function loadEnvironmentSafety(): array
    {
        return [
            'appEnv' => getenv('APP_ENV') ?: null,
            'isProduction' => 'NO',
        ];
    }
}
