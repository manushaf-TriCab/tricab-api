<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\DTO;

/**
 * Runtime capability snapshot handed to the client config endpoint so
 * features.ocr / features.emailNotifications describe what is actually wired
 * rather than a hard-coded constant.
 *
 * bankReconciliation and periodClose shipped in Phase 4 with no external
 * dependency, so they are on unconditionally.
 */
final class ExpenseFeatureFlags
{
    public function __construct(
        public readonly bool $ocr = false,
        public readonly bool $emailNotifications = false,
        public readonly bool $bankReconciliation = true,
        public readonly bool $periodClose = true,
        public readonly bool $legacyRead = false,
    ) {
    }

    public static function disabledIntegrations(): self
    {
        return new self(ocr: false, emailNotifications: false);
    }

    /** @return array<string, bool> */
    public function toArray(): array
    {
        return [
            'ocr' => $this->ocr,
            'emailNotifications' => $this->emailNotifications,
            'bankReconciliation' => $this->bankReconciliation,
            'periodClose' => $this->periodClose,
            'legacyRead' => $this->legacyRead,
        ];
    }
}
