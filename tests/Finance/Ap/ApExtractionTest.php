<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Finance\Ap\ApClassification;
use TriCab\Core\Finance\Ap\ApExtraction;
use TriCab\Core\Finance\Ap\ApExtractionCompany;

/** The source's grading (`_grade_extraction`), company matching (`_match_company`) and vendor-email rule, case by case. */
final class ApExtractionTest extends TestCase
{
    /** @return list<ApExtractionCompany> */
    private static function companies(): array
    {
        return [
            new ApExtractionCompany('TriCab (Australia) Pty Ltd', 'AU', 'Sydney', 'Cable Co'),
            new ApExtractionCompany('Tricab  NZ   Limited', 'NZ'),
            new ApExtractionCompany('TriCab Singapore Pte Ltd', 'SG'),
            new ApExtractionCompany('TriCab Retired Ltd', 'OLD', '', '', false),
        ];
    }

    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private static function fields(array $over = []): array
    {
        return [...ScriptedApAiClient::invoiceFields(), ...$over];
    }

    /** @return array<string, array{0: array<string, mixed>, 1: float, 2: ?string}> */
    public static function gradingCases(): array
    {
        return [
            'sound' => [[], 0.93, null],
            'low confidence' => [['extraction_confidence' => 0.5], 0.5, 'Low extraction confidence (50%)'],
            'exactly the threshold passes' => [['extraction_confidence' => 0.8], 0.8, null],
            'just below' => [['extraction_confidence' => 0.7999], 0.7999, 'Low extraction confidence (80%)'],
            'half rounds up, the specification (R25: 74.5 is 75%)' => [['extraction_confidence' => 0.745], 0.745, 'Low extraction confidence (75%)'],
            'missing confidence is zero' => [['extraction_confidence' => null], 0.0, 'Low extraction confidence (0%)'],
            'unreadable confidence is zero' => [['extraction_confidence' => 'high'], 0.0, 'Low extraction confidence (0%)'],
            'over one is clamped' => [['extraction_confidence' => 7], 1.0, null],
            'below zero is clamped' => [['extraction_confidence' => -2], 0.0, 'Low extraction confidence (0%)'],
            'quoted number is read' => [['extraction_confidence' => '0.9'], 0.9, null],
            'classification confidence stands in' => [['extraction_confidence' => null, 'classification_confidence' => 0.85], 0.85, null],
            'classification confidence low' => [['extraction_confidence' => null, 'classification_confidence' => 0.6], 0.6, 'Low extraction confidence (60%)'],
            'no invoice number' => [['invoice_no' => null], 0.93, 'Could not read the invoice number'],
            'blank vendor and zero total' => [['vendor' => '', 'total_amount' => 0, 'net_amount' => null], 0.93, 'Could not read the vendor, total'],
            'all three unread and low' => [
                ['invoice_no' => '', 'vendor' => null, 'total_amount' => null, 'net_amount' => null, 'extraction_confidence' => 0.1],
                0.1,
                'Could not read the invoice number, vendor, total; Low extraction confidence (10%)',
            ],
            'quoted zero is text, so present' => [['invoice_no' => '0'], 0.93, null],
            'amounts that do not add up' => [
                ['net_amount' => 1000, 'tax_amount' => 100, 'total_amount' => 1200.5],
                0.93,
                'Amounts do not add up: 1,000.00 + 100.00 = 1,100.00, but the total reads 1,200.50',
            ],
            'within two cents' => [['net_amount' => 100.0, 'tax_amount' => 10.0, 'total_amount' => 110.02], 0.93, null],
            'three cents out' => [
                ['net_amount' => 100.0, 'tax_amount' => 10.0, 'total_amount' => 110.03],
                0.93,
                'Amounts do not add up: 100.00 + 10.00 = 110.00, but the total reads 110.03',
            ],
            'an unknown amount is not a wrong one' => [['tax_amount' => null, 'total_amount' => 999], 0.93, null],
            'text amounts with a comma do not parse, so cannot be checked' => [['net_amount' => '1,000.00', 'total_amount' => 5], 0.93, null],
            'plain numeric text amounts are read' => [
                ['net_amount' => '10', 'tax_amount' => '1', 'total_amount' => '12'],
                0.93,
                'Amounts do not add up: 10.00 + 1.00 = 11.00, but the total reads 12.00',
            ],
            'every reason, in order' => [
                ['invoice_no' => null, 'extraction_confidence' => 0.2, 'net_amount' => 5, 'tax_amount' => 5, 'total_amount' => 50],
                0.2,
                'Could not read the invoice number; Low extraction confidence (20%); Amounts do not add up: 5.00 + 5.00 = 10.00, but the total reads 50.00',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $over
     */
    #[DataProvider('gradingCases')]
    public function test_the_review_reason_is_the_sources_text(array $over, float $confidence, ?string $reason): void
    {
        $out = ApExtraction::fromResult(self::fields($over), self::companies());

        self::assertSame($confidence, $out->extractionConfidence);
        self::assertSame($reason, $out->reviewReason);
        self::assertSame($reason === null ? 'In Progress' : 'Needs Review', $out->status());
    }

    public function test_large_amounts_are_grouped_by_thousands_in_the_reason(): void
    {
        $out = ApExtraction::fromResult(self::fields(['net_amount' => 1234567.891, 'tax_amount' => 0, 'total_amount' => 1]), self::companies());

        self::assertSame('Amounts do not add up: 1,234,567.89 + 0.00 = 1,234,567.89, but the total reads 1.00', $out->reviewReason);
    }

    public function test_the_fields_are_typed_and_a_number_written_for_text_becomes_text(): void
    {
        $out = ApExtraction::fromResult(self::fields(['invoice_no' => 12345, 'currency' => null, 'tax_id' => ['x'], 'po_number' => 7.5]), self::companies());

        self::assertSame('12345', $out->invoiceNo);
        self::assertSame('AUD', $out->currency);
        self::assertNull($out->taxId);
        self::assertSame('7.5', $out->poNumber);
        self::assertSame('INV-7', ApExtraction::fromResult(self::fields(['invoice_no' => null]), [])->invoiceNoOrGenerated(7));
        self::assertSame('Unknown Vendor', ApExtraction::fromResult(self::fields(['vendor' => '']), [])->vendorOrUnknown());
    }

    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function companyCases(): array
    {
        return [
            'exact' => ['TriCab (Australia) Pty Ltd', 'TriCab (Australia) Pty Ltd'],
            'case and spacing' => ['  tricab   (AUSTRALIA)  pty ltd ', 'TriCab (Australia) Pty Ltd'],
            'by code' => ['sg', 'TriCab Singapore Pte Ltd'],
            'the stored name has its spaces collapsed' => ['TRICAB NZ LIMITED', 'Tricab NZ Limited'],
            'punctuation drift, one containment hit' => ['TriCab Australia Pty. Ltd.', 'TriCab (Australia) Pty Ltd'],
            'name inside what the model wrote' => ['TriCab (Australia) Pty Ltd ACN 123 456 789', 'TriCab (Australia) Pty Ltd'],
            'two entities match, so none' => ['TriCab', null],
            'an inactive company is not matched' => ['TriCab Retired Ltd', null],
            'an inactive code is not matched' => ['OLD', null],
            'unknown' => ['Acme Pty Ltd', null],
            'empty' => ['   ', null],
            'null' => [null, null],
            'a site or former name does not match (prompt only)' => ['Cable Co', null],
        ];
    }

    #[DataProvider('companyCases')]
    public function test_a_company_is_a_choice_from_the_list_never_free_text(?string $written, ?string $expected): void
    {
        self::assertSame($expected, ApExtractionCompany::match($written, self::companies()));
    }

    public function test_the_prompt_list_is_the_active_named_companies_with_their_sites_and_former_names(): void
    {
        $list = ApExtractionCompany::promptList([...self::companies(), new ApExtractionCompany('  ')]);

        self::assertSame(
            [
                ['name' => 'TriCab (Australia) Pty Ltd', 'sites' => 'Sydney', 'former_names' => 'Cable Co'],
                ['name' => 'Tricab  NZ   Limited', 'sites' => '', 'former_names' => ''],
                ['name' => 'TriCab Singapore Pte Ltd', 'sites' => '', 'former_names' => ''],
            ],
            $list,
        );
    }

    /** @return array<string, array{0: ?string, 1: ?string, 2: ?string}> */
    public static function vendorEmailCases(): array
    {
        return [
            'what the invoice prints' => [' billing@acme.example.test ', 'colleague@tricab.com', 'billing@acme.example.test'],
            'internal printed address, external sender' => ['ap@tricab.com', 'vendor@acme.example.test', 'vendor@acme.example.test'],
            'nothing printed, external sender' => [null, 'vendor@acme.example.test', 'vendor@acme.example.test'],
            'internal on both sides' => ['ap@tricab.com', 'colleague@TRICAB.com', null],
            'printed text that is no address' => ['see invoice', 'vendor@acme.example.test', 'vendor@acme.example.test'],
            'no sender' => [null, null, null],
            'a sender that is not an address is kept, as the source kept it' => [null, 'Acme Accounts', 'Acme Accounts'],
        ];
    }

    #[DataProvider('vendorEmailCases')]
    public function test_the_vendor_email_is_never_one_of_our_own_addresses(?string $printed, ?string $sender, ?string $expected): void
    {
        self::assertSame($expected, ApExtraction::resolveVendorEmail($printed, $sender, ['tricab.com']));
    }

    public function test_extra_internal_domains_are_accepted_with_or_without_an_at_sign(): void
    {
        self::assertNull(ApExtraction::resolveVendorEmail('x@tricab.co.nz', null, [' @Tricab.co.nz ', 'tricab.com']));
        self::assertSame('x@tricab.co.nz', ApExtraction::resolveVendorEmail('x@tricab.co.nz', null, ['tricab.com']));
    }

    public function test_a_classification_reads_its_verdict_and_grades_its_fields(): void
    {
        $out = ApClassification::fromResult([
            ...self::fields(['extraction_confidence' => null]),
            'is_invoice' => true, 'classification_reason' => '', 'classification_confidence' => 1.4,
        ], self::companies());

        self::assertTrue($out->isInvoice);
        self::assertNull($out->reason, 'an empty reason is no reason');
        self::assertSame(1.0, $out->confidence);
        self::assertNull($out->extraction->reviewReason);
        self::assertSame('Invoice', ApClassification::emailLabel(true));
        self::assertSame('Other', ApClassification::emailLabel(false));

        $unsure = ApClassification::fromResult([
            ...self::fields(['extraction_confidence' => null]),
            'is_invoice' => 'true', 'classification_reason' => 'r', 'classification_confidence' => 0.4,
        ], self::companies());
        self::assertFalse($unsure->isInvoice, 'the service already turns a quoted boolean into one; PHP only trusts a real true');
        self::assertSame('Low extraction confidence (40%)', $unsure->extraction->reviewReason);
    }
}
