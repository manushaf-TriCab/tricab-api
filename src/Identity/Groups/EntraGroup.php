<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Groups;

/** A mail group as Entra describes it. */
final readonly class EntraGroup
{
    public function __construct(
        public string $id,
        public string $displayName,
        public ?string $mail,
        public ?string $description,
    ) {
    }
}
