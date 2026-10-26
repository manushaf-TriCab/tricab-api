<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap2\Ai;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Ap2\Ai\FakeAp2DraftWriter;
use TriCab\Api\Finance\Ap2\Ai\FoundryAp2DraftWriter;
use TriCab\Core\Finance\Ap2\Ai\Ap2AiUnavailable;
use TriCab\Core\Finance\Ap2\Ai\Ap2MailContext;
use TriCab\Core\Finance\Ap2\Ai\Ap2SupplierRef;

/** The Foundry draft writer: our facts and the fenced email, the clarification, and the drafts it refuses. */
final class FoundryAp2DraftWriterTest extends TestCase
{
    private Ap2AiWorld $world;

    protected function setUp(): void
    {
        $this->world = new Ap2AiWorld();
    }

    private function writer(): FoundryAp2DraftWriter
    {
        return new FoundryAp2DraftWriter($this->world->foundry(), Ap2AiWorld::prompt('tri-ap2-reply-draft'), 'Alex Chen, Accounts Payable, Tricab', Ap2AiWorld::nonce(...));
    }

    private static function mail(string $category = 'Supplier invoice', string $body = 'Please find invoice CM-2200 for 1,000 units, AUD 22,000 including tax, against PO-1001.'): Ap2MailContext
    {
        return new Ap2MailContext('m1', 'Invoice CM-2200', 'accounts@cable.example', $body, $category, '2026-10-26T01:00:00Z');
    }

    private static function supplier(): Ap2SupplierRef
    {
        return new Ap2SupplierRef('s1', 'Cable Materials Pty Ltd', 'accounts@cable.example', 'cable.example');
    }

    /** @return array<string, mixed> CM-2200 as the API presents it (the fields the writer reads) */
    private static function invoice(mixed ...$over): array
    {
        return Ap2AiWorld::merge(['id' => 'inv1', 'number' => 'CM-2200', 'currency' => 'AUD', 'total' => 22000.0, 'po' => 'PO-1001', 'stage' => 'Awaiting matching', 'credit' => 0.0, 'creditRef' => ''], $over);
    }

    public function testTheClarificationCarriesTheIssuesAsOurFacts(): void
    {
        $draft = "Hello,\n\nThank you for invoice CM-2200. Our review found that 900 of the 1,000 units have been received. Please provide the receipt evidence or a credit note for the shortfall.\n\nKind regards,\nAlex Chen, Accounts Payable, Tricab";
        $this->world->http->queue[] = Ap2AiWorld::json(['draft' => $draft, 'injection_suspected' => false]);

        $result = $this->writer()->draft(self::mail(), self::supplier(), self::invoice(credit: 2200.0, creditRef: 'CN-2200'), ['Received quantity is below invoiced quantity'], 'ask for the GRN');

        self::assertSame($draft, $result);
        $user = $this->world->lastUserText();
        self::assertStringContainsString('Supplier: Cable Materials Pty Ltd', $user);
        self::assertStringContainsString('Linked invoice: CM-2200, AUD 22,000.00, PO PO-1001, stage Awaiting matching', $user);
        self::assertStringContainsString('Credit applied: AUD 2,200.00 (CN-2200)', $user);
        self::assertStringContainsString("write the supplier clarification asking for what resolves them:\n- Received quantity is below invoiced quantity", $user);
        self::assertStringContainsString("<<<INSTRUCTION_abc123def456>>>\nask for the GRN\n<<<END_INSTRUCTION_abc123def456>>>", $user);
        self::assertStringContainsString('Sign off as: Alex Chen, Accounts Payable, Tricab', $user);
        $payload = $this->world->payload();
        self::assertArrayNotHasKey('tool_choice', $payload);
        self::assertSame(['draft', 'injection_suspected'], Ap2AiWorld::dig($payload, 'output_config', 'format', 'schema', 'required'));
    }

    public function testABankChangeIsNamedAsUnverifiedAndNoSupplierIsSaid(): void
    {
        $this->world->http->queue[] = Ap2AiWorld::json(['draft' => 'Thank you for your email. Bank detail changes require independent verification through our existing trusted contact. No account details have been changed.', 'injection_suspected' => false]);

        $this->writer()->draft(self::mail('Bank detail change', 'Please update our bank details.'), null, null, [], null);

        $user = $this->world->lastUserText();
        self::assertStringContainsString('Supplier: not matched yet', $user);
        self::assertStringContainsString('bank detail change: it is NOT verified', $user);
        self::assertStringContainsString('No invoice is linked to this email.', $user);
        self::assertStringNotContainsString('INSTRUCTION', $user);
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusedDrafts(): iterable
    {
        yield 'a payment date' => ['Thank you. Invoice CM-2200 will be paid on 3 November.', 'promised a payment date'];
        yield 'a payment timeframe' => ['Thank you. Payment will be made within 7 days.', 'promised a payment date'];
        yield 'we will pay by' => ['We will pay this by Friday.', 'promised a payment date'];
        yield 'markdown' => ["**Invoice CM-2200**\n\nThank you.", 'not plain text'];
        yield 'a heading' => ["# Reply\nThank you.", 'not plain text'];
        yield 'empty' => ['   ', 'empty draft'];
        yield 'too long' => [str_repeat('Thank you. ', 500), 'too long'];
    }

    #[DataProvider('refusedDrafts')]
    public function testADraftThatBreaksTheRulesIsRefused(string $draft, string $why): void
    {
        $this->world->http->queue[] = Ap2AiWorld::json(['draft' => $draft, 'injection_suspected' => false]);

        $this->expectException(Ap2AiUnavailable::class);
        $this->expectExceptionMessage($why);
        $this->writer()->draft(self::mail(), self::supplier(), null, [], null);
    }

    public function testADraftWithoutTheShapeIsAFailure(): void
    {
        $this->world->http->queue[] = Ap2AiWorld::json(['reply' => 'Thank you.']);

        $this->expectException(Ap2AiUnavailable::class);
        $this->expectExceptionMessage('The AI draft failed: invalid output: the draft is missing');
        $this->writer()->draft(self::mail(), self::supplier(), null, [], null);
    }

    public function testWhatCountsAsAPaymentPromise(): void
    {
        self::assertTrue(FoundryAp2DraftWriter::promisesPayment('It is scheduled for payment on the 30th.'));
        self::assertTrue(FoundryAp2DraftWriter::promisesPayment('The invoice will be processed by end of month.'));
        self::assertFalse(FoundryAp2DraftWriter::promisesPayment('We will review it against our purchase and receipt records.'));
        self::assertFalse(FoundryAp2DraftWriter::promisesPayment('We are reviewing the invoice and will contact you with an update.'));
        // Every seeded draft of the prototype passes.
        foreach (['Supplier invoice', 'Supplier statement', 'Credit note', 'Bank detail change', 'Other'] as $category) {
            self::assertFalse(FoundryAp2DraftWriter::promisesPayment(FakeAp2DraftWriter::template(self::mail($category), self::invoice(), [])), $category);
        }
    }

    public function testTheFakeUsesThePrototypesWording(): void
    {
        $fake = new FakeAp2DraftWriter();

        self::assertSame(
            'Thank you for invoice CM-2200. Our review identified: Received quantity is below invoiced quantity. Please provide the supporting PO, receipt evidence or applicable credit note so we can complete our review.',
            $fake->draft(self::mail(), self::supplier(), self::invoice(), ['Received quantity is below invoiced quantity'], null),
        );
        self::assertSame(
            'Thank you for sending invoice CM-2200. We have received it and will review it against our purchase and receipt records. We will contact you if any information is needed.',
            $fake->draft(self::mail(), self::supplier(), self::invoice(), [], null),
        );
        self::assertSame(
            'Thank you for your email. Bank detail changes require independent verification through our existing trusted contact. No account details have been changed.',
            $fake->draft(self::mail('Bank detail change'), null, null, [], null),
        );
        self::assertSame(
            'Thank you for credit note CN-2200. We will verify it against the invoice and receipt evidence before updating the record.',
            $fake->draft(self::mail('Credit note'), null, self::invoice(creditRef: 'CN-2200'), [], null),
        );
    }
}
