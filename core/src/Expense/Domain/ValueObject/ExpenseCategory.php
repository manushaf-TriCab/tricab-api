<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Domain\ValueObject;

/**
 * Preserves GL codes and legacy free-text categories without silent normalization.
 */
final class ExpenseCategory
{
    private function __construct(
        private readonly string $rawValue,
        private readonly ?string $otherCategory,
    ) {
    }

    public static function fromLegacy(?string $category, ?string $otherCategory = null): self
    {
        $raw = trim((string) $category);
        $other = $otherCategory !== null ? trim($otherCategory) : null;
        if ($other === '') {
            $other = null;
        }

        return new self($raw, $other);
    }

    public function rawValue(): string
    {
        return $this->rawValue;
    }

    public function otherCategory(): ?string
    {
        return $this->otherCategory;
    }

    /** True when value looks like a 5-digit GL code. */
    public function looksLikeGlCode(): bool
    {
        return preg_match('/^\d{5}$/', $this->rawValue) === 1;
    }
}