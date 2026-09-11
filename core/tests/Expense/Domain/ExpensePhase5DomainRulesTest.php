<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Expense\Domain;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Expense\Domain\Service\ExpenseOcrGstClassifier;
use TriCab\Core\Expense\Domain\Service\ExpenseOcrInputValidator;
use TriCab\Core\Expense\Domain\Service\ExpenseSubmitNotificationRecipients;
use TriCab\Core\Expense\Module;

final class ExpensePhase5DomainRulesTest extends TestCase
{
    public function test_gstClassifierTreatsOneEleventhOfTotalAsInclusive(): void
    {
        // 110.00 inclusive of 10% GST prints GST of 10.00 (= 110/11).
        $result = ExpenseOcrGstClassifier::classify('110.00', '10.00');
        self::assertSame(Module::GST_INCLUSIVE, $result['gstType']);
        self::assertFalse($result['suspicious']);
    }

    public function test_gstClassifierAcceptsLegacyFiveCentTolerance(): void
    {
        // 100/11 = 9.0909…; legacy accepted anything within 0.05 of that.
        $inside = ExpenseOcrGstClassifier::classify('100.00', '9.13');
        self::assertSame(Module::GST_INCLUSIVE, $inside['gstType']);

        $outside = ExpenseOcrGstClassifier::classify('100.00', '9.20');
        self::assertSame(Module::GST_EXCLUSIVE, $outside['gstType']);
        self::assertFalse($outside['suspicious']);
    }

    public function test_gstClassifierTreatsExclusiveGstAsExclusive(): void
    {
        $result = ExpenseOcrGstClassifier::classify('100.00', '10.00');
        self::assertSame(Module::GST_EXCLUSIVE, $result['gstType']);
        self::assertSame('outside_legacy_inclusive_tolerance', $result['reason']);
    }

    public function test_gstClassifierFlagsGstAtOrAboveTotalAsSuspicious(): void
    {
        foreach (['100.00', '150.00'] as $gst) {
            $result = ExpenseOcrGstClassifier::classify('100.00', $gst);
            self::assertSame(Module::GST_UNKNOWN, $result['gstType'], 'gst=' . $gst);
            self::assertTrue($result['suspicious'], 'gst=' . $gst);
            self::assertSame('gst_not_less_than_total', $result['reason']);
        }
    }

    public function test_gstClassifierHandlesMissingAndUnusableFigures(): void
    {
        $missingGst = ExpenseOcrGstClassifier::classify('100.00', null);
        self::assertSame(Module::GST_UNKNOWN, $missingGst['gstType']);
        self::assertFalse($missingGst['suspicious'], 'a receipt with no GST line is normal, not suspicious');

        $missingTotal = ExpenseOcrGstClassifier::classify(null, '10.00');
        self::assertSame(Module::GST_UNKNOWN, $missingTotal['gstType']);
        self::assertTrue($missingTotal['suspicious']);

        $zeroTotal = ExpenseOcrGstClassifier::classify('0.00', '0.00');
        self::assertTrue($zeroTotal['suspicious']);
        self::assertSame('total_not_positive', $zeroTotal['reason']);

        $negativeGst = ExpenseOcrGstClassifier::classify('100.00', '-5.00');
        self::assertTrue($negativeGst['suspicious']);
    }

    public function test_gstClassifierNormalisesFormattedAmounts(): void
    {
        $result = ExpenseOcrGstClassifier::classify('$1,100.00', '$100.00');
        self::assertSame(Module::GST_INCLUSIVE, $result['gstType']);
    }

    public function test_gstClassifierTreatsZeroGstAsExclusive(): void
    {
        $result = ExpenseOcrGstClassifier::classify('100.00', '0.00');
        self::assertSame(Module::GST_EXCLUSIVE, $result['gstType']);
        self::assertSame('zero_gst', $result['reason']);
        self::assertFalse($result['suspicious']);
    }

    public function test_submitRecipientsFollowLegacyModeSplit(): void
    {
        self::assertSame(
            ['owner@tricab.com', 'mgr@tricab.com'],
            ExpenseSubmitNotificationRecipients::resolve(
                Module::SUBMIT_DRAFT_THEN_SUBMIT,
                'owner@tricab.com',
                'mgr@tricab.com',
            ),
        );

        self::assertSame(
            ['owner@tricab.com'],
            ExpenseSubmitNotificationRecipients::resolve(
                Module::SUBMIT_IMMEDIATE,
                'owner@tricab.com',
                'mgr@tricab.com',
            ),
        );
    }

    public function test_submitRecipientsDropUnusableAddressesAndDuplicates(): void
    {
        self::assertSame(
            ['owner@tricab.com'],
            ExpenseSubmitNotificationRecipients::resolve(
                Module::SUBMIT_DRAFT_THEN_SUBMIT,
                'Owner@TriCab.com',
                'owner@tricab.com',
            ),
        );

        self::assertSame(
            ['owner@tricab.com'],
            ExpenseSubmitNotificationRecipients::resolve(
                Module::SUBMIT_DRAFT_THEN_SUBMIT,
                'owner@tricab.com',
                'not-an-address',
            ),
        );

        self::assertSame(
            [],
            ExpenseSubmitNotificationRecipients::resolve(Module::SUBMIT_DRAFT_THEN_SUBMIT, null, null),
        );
    }

    public function test_ocrInputValidatorAcceptsSupportedImages(): void
    {
        $result = ExpenseOcrInputValidator::inspect('fake-jpeg-bytes', 'receipt.JPG');
        self::assertTrue($result['ok']);
        self::assertSame('image/jpeg', $result['mimeType']);
        self::assertNull($result['pages']);
    }

    public function test_ocrInputValidatorRejectsUnsupportedAndEmptyFiles(): void
    {
        $unsupported = ExpenseOcrInputValidator::inspect('bytes', 'statement.xlsx');
        self::assertFalse($unsupported['ok']);
        self::assertSame('unsupported_file_type', $unsupported['reason']);

        $noName = ExpenseOcrInputValidator::inspect('bytes', null);
        self::assertFalse($noName['ok']);

        $empty = ExpenseOcrInputValidator::inspect('', 'receipt.png');
        self::assertFalse($empty['ok']);
        self::assertSame('empty_file', $empty['reason']);
    }

    public function test_ocrInputValidatorRejectsOversizedFiles(): void
    {
        $result = ExpenseOcrInputValidator::inspect(
            str_repeat('x', Module::OCR_MAX_BYTES + 1),
            'receipt.png',
        );
        self::assertFalse($result['ok']);
        self::assertSame('file_too_large', $result['reason']);
    }

    public function test_ocrInputValidatorBoundsPdfPages(): void
    {
        $short = ExpenseOcrInputValidator::inspect(self::pdfWithPages(2), 'receipt.pdf');
        self::assertTrue($short['ok']);
        self::assertSame(2, $short['pages']);

        $long = ExpenseOcrInputValidator::inspect(
            self::pdfWithPages(Module::OCR_MAX_PDF_PAGES + 1),
            'statement.pdf',
        );
        self::assertFalse($long['ok']);
        self::assertSame('pdf_too_many_pages', $long['reason']);
    }

    public function test_ocrInputValidatorRejectsPdfExtensionOnNonPdfBytes(): void
    {
        $result = ExpenseOcrInputValidator::inspect('this is not a pdf', 'receipt.pdf');
        self::assertFalse($result['ok']);
        self::assertSame('unreadable_pdf', $result['reason']);
    }

    private static function pdfWithPages(int $pages): string
    {
        return '%PDF-1.7' . str_repeat("\n/Type /Page\n", $pages) . "\n%%EOF";
    }
}
