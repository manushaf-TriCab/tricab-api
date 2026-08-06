<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Mapping;

final class OptionAliasResolver
{
    /** @param array<string, array<string, string>> $aliases definitionCode => normalizedLegacy => optionCode */
    public function __construct(
        private array $aliases = [],
    ) {
    }

    public function register(string $definitionCode, string $legacyValue, string $targetOptionCode): void
    {
        $norm = self::normalize($legacyValue);
        $this->aliases[$definitionCode][$norm] = $targetOptionCode;
    }

    public function resolve(?string $definitionCode, string $legacyValue): ?string
    {
        if ($definitionCode === null || $definitionCode === '') {
            return null;
        }
        $norm = self::normalize($legacyValue);

        return $this->aliases[$definitionCode][$norm] ?? null;
    }

    public static function normalize(string $value): string
    {
        $v = strtolower(trim($value));
        $v = preg_replace('/\s+/', ' ', $v) ?? $v;

        return $v;
    }
}
