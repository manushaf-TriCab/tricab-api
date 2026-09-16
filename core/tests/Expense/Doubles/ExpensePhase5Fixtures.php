<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Expense\Doubles;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Core\Expense\Domain\Entity\ExpenseBankFeedEntry;
use TriCab\Core\Expense\Domain\Entity\ExpenseReceipt;
use TriCab\Core\Expense\Domain\ValueObject\BankFeedMatchStatus;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseAttachmentRef;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseBankFeedPublicId;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseCategory;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseCurrency;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseMoney;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId;
use TriCab\Core\Expense\Module;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

/** Shared receipt / bank-feed builders for the Phase 5 test suites. */
final class ExpensePhase5Fixtures
{
    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-01T00:00:00Z', new DateTimeZone('UTC'));
    }

    public static function publicId(string $suffix): ExpenseReceiptPublicId
    {
        return ExpenseReceiptPublicId::fromString(
            sprintf('33333333-3333-4333-8333-33333333333%s', substr(md5($suffix), 0, 1)),
        );
    }

    public static function draftReceipt(
        string $suffix,
        UserId $ownerUserId,
        ?ExpenseAttachmentRef $attachment = null,
        string $totalAmount = '110.00',
    ): ExpenseReceipt {
        $now = self::now();

        return ExpenseReceipt::createDraft(
            publicId: self::publicId($suffix),
            ownerUserId: $ownerUserId,
            vendorName: 'Vendor ' . strtoupper($suffix),
            totalAmount: ExpenseMoney::fromString($totalAmount),
            gstAmount: ExpenseMoney::fromString('10.00'),
            currency: ExpenseCurrency::fromString('AUD'),
            gstType: Module::GST_INCLUSIVE,
            receiptDate: $now,
            category: ExpenseCategory::fromLegacy('64100'),
            costCentre: 'MEL',
            description: null,
            attachment: $attachment,
            now: $now,
            managerUserId: null,
        );
    }

    public static function pendingReceipt(
        string $suffix,
        UserId $ownerUserId,
        UserId $managerUserId,
        ?ExpenseAttachmentRef $attachment = null,
    ): ExpenseReceipt {
        $receipt = self::draftReceipt($suffix, $ownerUserId, $attachment);
        $receipt->submit($ownerUserId, $managerUserId, self::now());

        return $receipt;
    }

    public static function bankFeedEntry(string $cardholderEmail): ExpenseBankFeedEntry
    {
        return ExpenseBankFeedEntry::reconstitute(
            id: null,
            publicId: ExpenseBankFeedPublicId::fromString('44444444-4444-4444-8444-444444444441'),
            bankReference: 'P5-CHASE-1',
            cardholderEmail: $cardholderEmail,
            cardholderUserId: null,
            firstName: 'Owner',
            lastName: 'User',
            country: Module::COUNTRY_AU,
            transactionDate: self::now(),
            postingDate: self::now(),
            statementDate: self::now(),
            merchantName: 'Cafe Melbourne',
            merchantCity: 'Melbourne',
            merchantCountry: 'AU',
            merchantPostcode: '3000',
            amount: ExpenseMoney::fromSignedString('25.00'),
            currency: ExpenseCurrency::fromString('AUD'),
            foreignAmount: null,
            foreignCurrency: null,
            netAmount: null,
            taxAmount: null,
            transactionType: 'purchase',
            merchantCategoryGroup: null,
            matchStatus: BankFeedMatchStatus::unmatched(),
            receiptId: null,
            periodId: null,
            resolution: null,
            offsetBankFeedEntryId: null,
            resolutionNote: null,
            resolvedByUserId: null,
            legacyResolvedBy: null,
            resolvedAtUtc: null,
            legacyUploadedBy: null,
            legacyBankFeedId: null,
            uploadedAtUtc: self::now(),
        );
    }
}
