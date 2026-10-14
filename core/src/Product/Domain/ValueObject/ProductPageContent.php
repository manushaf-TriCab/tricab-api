<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\ValueObject;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;

/**
 * Per-product presentation settings of the Typical Applications panel on the customer view:
 * whether the Product Marking section is shown, and an OPTIONAL free-text note under the Core Colours chips
 * (with its own visibility flag).
 *
 * Product Marking is OFF unless an administrator explicitly enabled it (a missing settings row never exposes the section on
 * existing - including published - products). The optional `version` is the optimistic-concurrency token of the stored row
 * (null when no row exists); it is read-only metadata and never part of equality of the settings themselves.
 *
 * The Product Marking TEXT itself is not stored here: it is the existing PRODUCT-MARKING product specification.
 * The note is NOT part of the CORE-COLOURS specification. Missing settings resolve to: marking OFF, no note, note shown
 * (when it has text) - so products without a settings row render exactly as before.
 */
final readonly class ProductPageContent
{
    public const NOTE_MAX_LENGTH = 500;

    private function __construct(
        private bool $showProductMarking,
        private ?string $coreColoursNote,
        private bool $showCoreColoursNote,
        private ?string $version = null,
    ) {
    }

    public static function defaults(): self
    {
        return new self(false, null, true);
    }

    public static function resolve(?bool $showProductMarking, ?string $coreColoursNote, ?bool $showCoreColoursNote, ?string $version = null): self
    {
        return new self($showProductMarking ?? false, self::normalizeNote($coreColoursNote), $showCoreColoursNote ?? true, $version);
    }

    /** Same settings carrying the stored row's concurrency token (set by repositories on read). */
    public function withVersion(?string $version): self
    {
        return new self($this->showProductMarking, $this->coreColoursNote, $this->showCoreColoursNote, $version);
    }

    /** Concurrency token of the stored row; null when the product has no settings row yet. */
    public function version(): ?string
    {
        return $this->version;
    }

    /**
     * Strict input validation for the admin write path.
     */
    public static function fromInput(mixed $showProductMarking, mixed $coreColoursNote, mixed $showCoreColoursNote): self
    {
        if (!is_bool($showProductMarking)) {
            throw InvalidProductValue::for('showProductMarking', 'expected a boolean');
        }
        if (!is_bool($showCoreColoursNote)) {
            throw InvalidProductValue::for('showCoreColoursNote', 'expected a boolean');
        }
        if ($coreColoursNote !== null && !is_string($coreColoursNote)) {
            throw InvalidProductValue::for('coreColoursNote', 'expected a string or null');
        }
        if (is_string($coreColoursNote)) {
            if (str_contains($coreColoursNote, "\0")) {
                throw InvalidProductValue::for('coreColoursNote', 'contains an invalid character');
            }
            $normalized = self::normalizeNote($coreColoursNote);
            if ($normalized !== null && mb_strlen($normalized) > self::NOTE_MAX_LENGTH) {
                throw InvalidProductValue::for('coreColoursNote', 'must be at most ' . self::NOTE_MAX_LENGTH . ' characters');
            }
        }

        return self::resolve($showProductMarking, $coreColoursNote, $showCoreColoursNote);
    }

    public function showProductMarking(): bool
    {
        return $this->showProductMarking;
    }

    public function coreColoursNote(): ?string
    {
        return $this->coreColoursNote;
    }

    public function showCoreColoursNote(): bool
    {
        return $this->showCoreColoursNote;
    }

    /** CRLF -> LF, trimmed; empty -> null. Interior line breaks are preserved. */
    private static function normalizeNote(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $normalized = trim(str_replace(["\r\n", "\r"], "\n", $value));

        return $normalized === '' ? null : $normalized;
    }
}
