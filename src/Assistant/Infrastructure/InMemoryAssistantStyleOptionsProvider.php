<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Infrastructure;

use TriCab\Api\Assistant\Application\AssistantStyleOptionsProvider;
use TriCab\Core\Product\Contract\ProductStyleRepository;

final readonly class InMemoryAssistantStyleOptionsProvider implements AssistantStyleOptionsProvider
{
    private const MAX_PAGE_SIZE = 50;

    public function __construct(private ProductStyleRepository $styles)
    {
    }

    public function search(
        array $styleCodes,
        ?string $text,
        ?string $cableType,
        int $page,
        int $pageSize,
    ): array {
        unset($cableType);
        $wanted = array_map('strtoupper', array_map('trim', $styleCodes));
        // No synonym facets in memory, so text matches what is here: the code
        // and the display name. Every token must match, as in the SQL provider.
        $tokens = array_values(array_filter(
            preg_split('/\s+/', trim((string) $text)) ?: [],
            static fn (string $token): bool => $token !== '',
        ));

        $matched = [];
        foreach ($this->styles->listAll() as $style) {
            if (!$style->isActive) {
                continue;
            }
            if ($wanted !== [] && !in_array(strtoupper($style->styleCode), $wanted, true)) {
                continue;
            }
            $haystack = $style->styleCode . ' ' . $style->displayName;
            foreach ($tokens as $token) {
                if (stripos($haystack, $token) === false) {
                    continue 2;
                }
            }
            $matched[] = [
                'styleCode' => $style->styleCode,
                'displayName' => $style->displayName,
                'facets' => [],
            ];
        }

        $safePageSize = max(1, min(self::MAX_PAGE_SIZE, $pageSize));
        $safePage = max(1, $page);

        return [
            'items' => array_slice($matched, ($safePage - 1) * $safePageSize, $safePageSize),
            'totalItems' => count($matched),
            'page' => $safePage,
            'pageSize' => $safePageSize,
        ];
    }
}
