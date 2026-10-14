<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Infrastructure;

use TriCab\Api\Assistant\Application\AssistantCodeProduct;
use TriCab\Api\Assistant\Application\AssistantCodeProductLookup;

final class InMemoryAssistantCodeProductLookup implements AssistantCodeProductLookup
{
    /** @var array<string, AssistantCodeProduct> */
    private array $byCode = [];

    public function add(string $codeValue, AssistantCodeProduct $product): void
    {
        $this->byCode[strtoupper($codeValue)] = $product;
    }

    public function forCode(string $codeValue, ?string $styleCode, ?int $coreCount): ?AssistantCodeProduct
    {
        return $this->byCode[strtoupper($codeValue)] ?? null;
    }
}
