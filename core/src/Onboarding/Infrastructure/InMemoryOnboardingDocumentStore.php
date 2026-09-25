<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Infrastructure;

use TriCab\Core\Onboarding\Application\DTO\Document\RenderedDocument;
use TriCab\Core\Onboarding\Contract\OnboardingDocumentStore;

final class InMemoryOnboardingDocumentStore implements OnboardingDocumentStore
{
    /** @var array<string, RenderedDocument> */
    public array $objects = [];

    public bool $failNext = false;

    public function put(string $blobPath, RenderedDocument $document): array
    {
        if ($this->failNext) {
            $this->failNext = false;
            throw new \RuntimeException('blob store unavailable');
        }
        $this->objects[$blobPath] = $document;

        return ['container' => 'onboarding', 'path' => $blobPath];
    }
}
