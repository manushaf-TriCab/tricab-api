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

    /** @var array<string, AssistantCodeProduct> */
    private array $byStyle = [];

    public function addStyle(string $styleCode, AssistantCodeProduct $product): void
    {
        $this->byStyle[strtoupper($styleCode)] = $product;
    }

    public function forCode(string $codeValue, ?string $styleCode, ?int $coreCount): ?AssistantCodeProduct
    {
        return $this->byCode[strtoupper($codeValue)] ?? null;
    }

    public function forStyles(array $styleCodes): array
    {
        $found = [];
        foreach ($styleCodes as $styleCode) {
            $key = strtoupper(trim($styleCode));
            if (isset($this->byStyle[$key])) {
                $found[$key] = $this->byStyle[$key];
            }
        }

        return $found;
    }
}
