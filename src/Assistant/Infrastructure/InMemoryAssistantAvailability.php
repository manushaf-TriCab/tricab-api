<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Infrastructure;

use TriCab\Api\Assistant\Application\AssistantAvailability;

final class InMemoryAssistantAvailability implements AssistantAvailability
{
    /** @var array<string, self::STOCK|self::MAKE> */
    private array $byCode = [];

    /** @param self::STOCK|self::MAKE $status */
    public function set(string $code, string $status): void
    {
        $this->byCode[strtoupper($code)] = $status;
    }

    public function forCodes(array $codes, ?string $regionCode): array
    {
        $availability = [];
        foreach ($codes as $code) {
            $status = $this->byCode[strtoupper($code)] ?? null;
            if ($status !== null) {
                $availability[$code] = $status;
            }
        }

        return $availability;
    }
}
