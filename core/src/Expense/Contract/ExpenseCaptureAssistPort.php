<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Contract;

/**
 * Assistive OCR / AI only — never authoritative without validation/save.
 */
interface ExpenseCaptureAssistPort
{
    /**
     * @return array<string, mixed>
     */
    public function suggestReceiptFields(string $fileContent, string $mimeType): array;

    /**
     * @param array<string, string> $categories code => label
     */
    public function suggestCategory(string $vendor, string $rawText, array $categories): ?string;
}