<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Ap2\Ai\NullAp2MailClassifier;
use TriCab\Core\Finance\Ap2\Domain\Ap2Mail;

/** The rule-based category guess used while AI is off. */
final class Ap2RuleClassifierTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>, string}> */
    public static function cases(): iterable
    {
        yield 'tax invoice with a pdf' => ['Tax invoice CM-9000', ['CM-9000.pdf'], 'Supplier invoice'];
        yield 'invoice named only by the file' => ['Hello', ['INVOICE_55.PDF'], 'Supplier invoice'];
        yield 'invoice without a pdf is only Other' => ['Invoice CM-9000', [], 'Other'];
        yield 'invoice with a non-pdf file is only Other' => ['Invoice CM-9000', ['scan.png'], 'Other'];
        yield 'credit note' => ['Credit note CN-1', ['cn.pdf'], 'Credit note'];
        yield 'credit beats invoice' => ['Credit for invoice 12', ['invoice-12.pdf'], 'Credit note'];
        yield 'statement' => ['Statement for October', [], 'Supplier statement'];
        yield 'statement from the file name' => ['Account', ['Oct-statement.pdf'], 'Supplier statement'];
        yield 'remittance' => ['Remittance advice', [], 'Payment enquiry'];
        yield 'payment' => ['Where is my payment?', [], 'Payment enquiry'];
        yield 'bank details' => ['Our bank details have changed', [], 'Bank detail change'];
        yield 'change of bank' => ['Change of bank account', [], 'Bank detail change'];
        yield 'bank change beats payment and invoice' => ['Payment: new bank details for invoice 9', ['invoice-9.pdf'], 'Bank detail change'];
        yield 'nothing matches' => ['Hello there', [], 'Other'];
    }

    /** @param list<string> $files */
    #[DataProvider('cases')]
    public function test_the_guess(string $subject, array $files, string $expected): void
    {
        $guess = (new NullAp2MailClassifier())->classify($subject, 'a@b.example', 'body says credit and payment', $files, []);

        self::assertSame($expected, $guess->category);
        self::assertContains($guess->category, Ap2Mail::CATEGORIES);
        self::assertSame(0.3, $guess->confidence);
        self::assertNull($guess->supplierId);
        self::assertStringStartsWith('Rule-based guess (AI is off): ', (string) $guess->summary);
    }

    public function test_the_body_is_never_read(): void
    {
        $guess = (new NullAp2MailClassifier())->classify('Hi', 'a@b.example', 'please update our bank details', [], []);

        self::assertSame('Other', $guess->category);
    }
}
