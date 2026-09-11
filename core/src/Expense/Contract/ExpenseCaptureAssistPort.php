<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Contract;

/**
 * Assistive OCR / AI only — never authoritative without validation/save.
 */
interface ExpenseCaptureAssistPort
{
    /**
     * False when no extraction backend is configured, so callers can fail with a
     * controlled error instead of returning an empty suggestion that looks like
     * "nothing found on this receipt".
     */
    public function isEnabled(): bool;

    /**
     * Extract candidate receipt fields.
     *
     * Returns either a flat `field => value` map or the richer
     * `['fields' => [field => ['value' =>, 'confidence' =>, 'source' =>]], 'warnings' => []]`
     * shape; {@see \TriCab\Core\Expense\Application\DTO\ExpenseOcrSuggestion}
     * normalises both.
     *
     * @return array<string, mixed>
     */
    public function suggestReceiptFields(string $fileContent, string $mimeType): array;

    /**
     * @param array<string, string> $categories code => label
     */
    public function suggestCategory(string $vendor, string $rawText, array $categories): ?string;
}
