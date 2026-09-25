<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Contract;

use TriCab\Core\Onboarding\Application\DTO\Document\RenderedDocument;

/**
 * Where generated document bytes live: a private blob container (B1), never
 * Azure SQL.
 */
interface OnboardingDocumentStore
{
    /**
     * @return array{container: string, path: string}
     */
    public function put(string $blobPath, RenderedDocument $document): array;
}
