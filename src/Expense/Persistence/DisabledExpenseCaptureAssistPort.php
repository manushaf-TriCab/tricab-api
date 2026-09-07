<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Persistence;

use TriCab\Core\Expense\Contract\ExpenseCaptureAssistPort;

/** Never calls Azure in tests / Stage 2 default. */
final class DisabledExpenseCaptureAssistPort implements ExpenseCaptureAssistPort
{
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