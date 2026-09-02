<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Infrastructure;

use TriCab\Api\Assistant\Application\AssistantStyleOptionsProvider;
use TriCab\Core\Product\Contract\ProductStyleRepository;

final readonly class InMemoryAssistantStyleOptionsProvider implements AssistantStyleOptionsProvider
{
    public function __construct(private ProductStyleRepository $styles)
    {
    }

    public function list(array $styleCodes, ?string $cableType, int $limit): array
    {
        unset($cableType);
        $wanted = array_map('strtoupper', array_map('trim', $styleCodes));
        $result = [];
        foreach ($this->styles->listAll() as $style) {
            if (!$style->isActive || ($wanted !== [] && !in_array(strtoupper($style->styleCode), $wanted, true))) {
                continue;
            }
            $result[] = [
                'styleCode' => $style->styleCode,
                'displayName' => $style->displayName,
                'facets' => [],
            ];
            if (count($result) >= max(1, min(30, $limit))) {
                break;
            }
        }

        return $result;
    }
}
