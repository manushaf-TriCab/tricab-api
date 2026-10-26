<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Odoo;

/**
 * A supplier bank account in Odoo (`res.partner.bank`), for the bank-change check. MASKED: only the last three
 * characters of the number are kept (`•••• 678`); the full number is never put in this object, so it cannot be
 * stored, logged or shown. `changedAt` is Odoo's last write (UTC, `yyyy-MM-dd HH:mm:ss`).
 */
final readonly class Ap2OdooBankAccount
{
    public function __construct(
        public int $id,
        public int $partnerId,
        public string $maskedNumber,
        public string $bankName,
        public bool $active,
        public ?bool $trustedForPayments,
        public ?string $createdAt,
        public ?string $changedAt,
    ) {
    }

    /** `•••• 678` from `123-456 78678`: the last three letters or digits, everything else hidden. */
    public static function mask(string $number): string
    {
        $plain = (string) preg_replace('/[^0-9A-Za-z]/', '', $number);
        if ($plain === '') {
            return '';
        }

        return '•••• ' . substr($plain, -min(3, max(1, strlen($plain) - 3)));
    }
}
