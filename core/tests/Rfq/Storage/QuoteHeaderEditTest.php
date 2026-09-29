<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Rfq\Storage;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Rfq\Application\DTO\RfqCustomerOption;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;
use TriCab\Core\Rfq\Storage\QuoteHeaderEdit;
use TriCab\Core\Rfq\Storage\StoredRevision;

/** Parity plan E2.3: the Quote Details panel onto the source's `quote_details` keys. */
final class QuoteHeaderEditTest extends TestCase
{
    public function test_an_empty_header_is_a_blank_one_in_australia(): void
    {
        $details = QuoteHeaderEdit::fromInput([])->applyTo([], null);

        self::assertSame('', $details['customer_id']);
        self::assertSame('', $details['validtill']);
        self::assertSame('australia', $details['region']);
        self::assertSame('AUD', $details['currency']);
    }

    public function test_other_stored_keys_are_kept(): void
    {
        $details = QuoteHeaderEdit::fromInput(['contact' => 'Jane'])->applyTo(['directives' => [['kind' => 'exclude']], 'contact' => 'Old'], null);

        self::assertSame([['kind' => 'exclude']], $details['directives']);
        self::assertSame('Jane', $details['contact']);
    }

    public function test_the_customer_comes_from_the_book(): void
    {
        $customer = new RfqCustomerOption(12, 'ACME', 'Acme Pty Ltd', 'WA', 'PER');
        $details = QuoteHeaderEdit::fromInput(['customerId' => 12])->applyTo(['customer_code' => 'FORGED'], $customer);

        self::assertSame(['12', 'ACME', 'Acme Pty Ltd'], [$details['customer_id'], $details['customer_code'], $details['customer_name']]);
    }

    public function test_valid_till_is_stored_as_the_source_writes_it_and_read_back(): void
    {
        $details = QuoteHeaderEdit::fromInput(['validTill' => '2026-02-03'])->applyTo([], null);
        self::assertSame('03/02/26', $details['validtill']);

        $header = StoredRevision::header(['customer_id' => '12'] + $details);
        self::assertSame('2026-02-03', $header->validTill);
        self::assertSame('12', $header->customerId);
        self::assertNull(QuoteHeaderEdit::isoValidTill('31/02/26'));
        self::assertNull(QuoteHeaderEdit::isoValidTill('2026-02-03'));
    }

    public function test_codes_are_upper_cased_and_text_trimmed(): void
    {
        $details = QuoteHeaderEdit::fromInput([
            'author' => ' scotth ', 'salesOffice' => 'per', 'payTerms' => 'eom030nd', 'customerReference' => '  Job 42 ',
        ])->applyTo([], null);

        self::assertSame(['SCOTTH', 'PER', 'EOM030ND', 'Job 42'], [$details['author'], $details['salesoffice'], $details['payterms'], $details['cusref']]);
    }

    public function test_bad_input_names_the_field(): void
    {
        $cases = [
            'header must be' => 'ACME',
            'customerId' => ['customerId' => '12'],
            'customerId ' => ['customerId' => 0],
            'contact' => ['contact' => 42],
            'comment' => ['comment' => str_repeat('x', 501)],
            'validTill' => ['validTill' => '2026-02-30'],
            'region' => ['region' => 'mars'],
            'currency' => ['currency' => 'EUR'],
        ];
        foreach ($cases as $field => $input) {
            try {
                QuoteHeaderEdit::fromInput($input);
                self::fail('accepted ' . json_encode($input));
            } catch (InvalidRfqValue $refused) {
                self::assertStringContainsString(trim($field), $refused->getMessage());
            }
        }
    }
}
