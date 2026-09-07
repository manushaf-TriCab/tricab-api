<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Domain\ValueObject\ExpenseMigrationReadiness;

/**
 * Future mapping report only — does not execute migration.
 */
final class ExpenseMigrationDryRunClassifier
{
    /**
     * @param array{created_by?: ?string, manager_login?: ?string, category?: ?string, file_url?: ?string, owner_matched?: bool, manager_matched?: bool} $row
     */
    public function classify(array $row): ExpenseMigrationReadiness
    {
        $ownerMatched = (bool) ($row['owner_matched'] ?? false);
        $managerMatched = (bool) ($row['manager_matched'] ?? false);
        $category = trim((string) ($row['category'] ?? ''));
        $fileUrl = trim((string) ($row['file_url'] ?? ''));

        if (!$ownerMatched) {
            return ExpenseMigrationReadiness::fromString(ExpenseMigrationReadiness::OWNER_UNMATCHED);
        }
        if (!$managerMatched) {
            return ExpenseMigrationReadiness::fromString(ExpenseMigrationReadiness::MANAGER_UNMATCHED);
        }
        if ($category !== '' && preg_match('/^\d{5}$/', $category) !== 1) {
            return ExpenseMigrationReadiness::fromString(ExpenseMigrationReadiness::CATEGORY_REVIEW);
        }
        if ($fileUrl === '' || str_contains($fileUrl, '/wp-content/')) {
            return ExpenseMigrationReadiness::fromString(ExpenseMigrationReadiness::ATTACHMENT_REVIEW);
        }

        return ExpenseMigrationReadiness::fromString(ExpenseMigrationReadiness::READY);
    }
}