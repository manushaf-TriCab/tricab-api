<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\DTO;

/**
 * Assistive extraction result. Every field carries its own confidence and the
 * component that produced it so the client can show provenance and the user
 * stays the one who commits values to the receipt.
 */
final class ExpenseOcrSuggestion
{
    public const SOURCE_DOCUMENT_INTELLIGENCE = 'document_intelligence';
    public const SOURCE_OPENAI = 'openai';
    public const SOURCE_DERIVED = 'derived';

    /** @var list<string> */
    public const FIELDS = [
        'vendorName',
        'receiptDate',
        'totalAmount',
        'gstAmount',
        'gstType',
        'currency',
        'categoryCode',
    ];

    /**
     * @param array<string, array{value: string|null, confidence: float|null, source: string}> $fields
     * @param list<string> $warnings
     */
    private function __construct(
        private readonly array $fields,
        private readonly array $warnings,
    ) {
    }

    /**
     * Normalises whatever the capture-assist port produced into the documented
     * shape, dropping anything that is not a recognised field.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromPortPayload(array $payload): self
    {
        /** @var array<string, mixed> $raw */
        $raw = is_array($payload['fields'] ?? null) ? $payload['fields'] : $payload;

        $fields = [];
        foreach (self::FIELDS as $name) {
            $candidate = $raw[$name] ?? null;
            if ($candidate === null) {
                continue;
            }
            $field = self::normalizeField($candidate);
            if ($field !== null) {
                $fields[$name] = $field;
            }
        }

        $warnings = [];
        if (is_array($payload['warnings'] ?? null)) {
            foreach ($payload['warnings'] as $warning) {
                if (is_string($warning) && trim($warning) !== '') {
                    $warnings[] = trim($warning);
                }
            }
        }

        return new self($fields, $warnings);
    }

    public static function empty(): self
    {
        return new self([], []);
    }

    /**
     * @return array{value: string|null, confidence: float|null, source: string}|null
     */
    public function field(string $name): ?array
    {
        return $this->fields[$name] ?? null;
    }

    public function value(string $name): ?string
    {
        return $this->fields[$name]['value'] ?? null;
    }

    public function withField(string $name, ?string $value, ?float $confidence, string $source): self
    {
        $fields = $this->fields;
        $fields[$name] = [
            'value' => $value,
            'confidence' => $confidence,
            'source' => $source,
        ];

        return new self($fields, $this->warnings);
    }

    public function withWarning(string $warning): self
    {
        $trimmed = trim($warning);
        if ($trimmed === '' || in_array($trimmed, $this->warnings, true)) {
            return $this;
        }

        return new self($this->fields, [...$this->warnings, $trimmed]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $ordered = [];
        foreach (self::FIELDS as $name) {
            if (isset($this->fields[$name])) {
                $ordered[$name] = $this->fields[$name];
            }
        }

        return [
            'fields' => $ordered,
            'warnings' => $this->warnings,
            // The client must apply these explicitly; OCR never writes a receipt.
            'applied' => false,
        ];
    }

    /**
     * @return array{value: string|null, confidence: float|null, source: string}|null
     */
    private static function normalizeField(mixed $candidate): ?array
    {
        if (is_string($candidate) || is_int($candidate) || is_float($candidate)) {
            return [
                'value' => self::stringOrNull((string) $candidate),
                'confidence' => null,
                'source' => self::SOURCE_DOCUMENT_INTELLIGENCE,
            ];
        }

        if (!is_array($candidate)) {
            return null;
        }

        $value = $candidate['value'] ?? null;
        $confidence = $candidate['confidence'] ?? null;
        $source = $candidate['source'] ?? self::SOURCE_DOCUMENT_INTELLIGENCE;

        return [
            'value' => is_string($value) || is_int($value) || is_float($value)
                ? self::stringOrNull((string) $value)
                : null,
            'confidence' => is_int($confidence) || is_float($confidence)
                ? round(max(0.0, min(1.0, (float) $confidence)), 4)
                : null,
            'source' => is_string($source) && trim($source) !== ''
                ? trim($source)
                : self::SOURCE_DOCUMENT_INTELLIGENCE,
        ];
    }

    private static function stringOrNull(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
