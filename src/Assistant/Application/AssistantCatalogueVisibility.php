<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Application;

enum AssistantCatalogueVisibility: string
{
    case PUBLIC = 'public';
    case STAFF = 'staff';

    public function publishedOnly(): bool
    {
        return $this === self::PUBLIC;
    }

    /** @return list<string> */
    public function allowedStatusCodes(): array
    {
        return $this === self::STAFF ? ['published', 'draft'] : [];
    }
}
