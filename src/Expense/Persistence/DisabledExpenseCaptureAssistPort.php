<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Persistence;

use TriCab\Core\Expense\Contract\ExpenseCaptureAssistPort;

/**
 * Never calls Azure. This is the default binding in tests and in any environment
 * without OCR credentials, so `isEnabled()` returning false is what makes the
 * OCR endpoint answer with a controlled "not enabled" error.
 */
final class DisabledExpenseCaptureAssistPort implements ExpenseCaptureAssistPort
{
    public function isEnabled(): bool
    {
        return false;
    }

    public function suggestReceiptFields(string $fileContent, string $mimeType): array
    {
        unset($fileContent, $mimeType);

        return [];
    }

    public function suggestCategory(string $vendor, string $rawText, array $categories): ?string
    {
        unset($vendor, $rawText, $categories);

        return null;
    }
}