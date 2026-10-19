<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Infrastructure\Foundry;

use RuntimeException;

final class FoundryProviderUnavailable extends RuntimeException
{
    /** @var array<string, mixed>|null */
    private ?array $partialSpec = null;

    /**
     * The product-finder requirement the agent had gathered before it failed,
     * so a fallback reply can still carry it.
     *
     * @param array<string, mixed> $spec
     */
    public function attachPartialSpec(array $spec): void
    {
        $this->partialSpec = $spec;
    }

    /** @return array<string, mixed>|null */
    public function partialSpec(): ?array
    {
        return $this->partialSpec;
    }
}
