<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Application;

interface AssistantStyleOptionsProvider
{
    /**
     * Search the style register.
     *
     * Was `list($styleCodes, $cableType, $limit)`, and the rename records a
     * real change rather than a tidy-up: that method could only enumerate, and
     * only the alphabetically first 30 of 145 eligible styles, with no paging
     * and no way to say what a style is *for*. Asked which styles suit
     * earthing, the agent could reach A through E1 and no further — LP and LS,
     * the two styles whose text actually says "used for earthing of mains and
     * sub mains", were unreachable unless the customer already knew the codes.
     * Worse, the truncation was silent: 30-of-145 came back indistinguishable
     * from 30-of-30, so a partial view read as the whole catalogue.
     *
     * Hence `$text`, and hence the pagination in the return: a caller must be
     * able to tell that it is holding a slice.
     *
     * @param list<string> $styleCodes
     * @return array{items: list<array<string, mixed>>, totalItems: int, page: int, pageSize: int}
     */
    public function search(
        array $styleCodes,
        ?string $text,
        ?string $cableType,
        int $page,
        int $pageSize,
    ): array;
}
