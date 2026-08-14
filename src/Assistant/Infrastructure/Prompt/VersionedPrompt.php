<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Infrastructure\Prompt;

final readonly class VersionedPrompt
{
    public function __construct(
        public string $name,
        public string $version,
        public string $content,
    ) {
    }

    public function id(): string
    {
        return $this->name . '-' . $this->version;
    }
}
