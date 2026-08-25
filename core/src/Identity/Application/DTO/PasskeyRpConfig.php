<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO;

final readonly class PasskeyRpConfig
{
    /**
     * @param list<string> $allowedOrigins
     */
    public function __construct(
        public string $rpId,
        public string $rpName,
        public array $allowedOrigins,
    ) {
    }

    public function allowsOrigin(string $origin): bool
    {
        $normalized = rtrim(strtolower(trim($origin)), '/');
        foreach ($this->allowedOrigins as $allowed) {
            if (rtrim(strtolower(trim($allowed)), '/') === $normalized) {
                return true;
            }
        }

        return false;
    }

    public function primaryOrigin(): string
    {
        return $this->allowedOrigins[0] ?? '';
    }
}
