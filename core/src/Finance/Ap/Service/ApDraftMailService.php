<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Service;

use InvalidArgumentException;
use TriCab\Core\Finance\Ap\ApAiService;
use TriCab\Core\Finance\Ap\ApDraft;
use TriCab\Core\Finance\Ap\ApDraftEmail;
use TriCab\Core\Finance\Ap\ApDraftInvoice;
use TriCab\Core\Finance\Ap\Contract\ApInvoiceStore;
use TriCab\Core\Finance\Ap\Domain\ApActor;
use TriCab\Core\Finance\Ap\Domain\ApEmail;
use TriCab\Core\Finance\Ap\Domain\ApInvalid;
use TriCab\Core\Finance\Ap\Domain\ApInvoice;
use TriCab\Core\Finance\Ap\Domain\ApNotFound;
use TriCab\Core\Finance\Ap\Domain\ApUpstream;
use TriCab\Core\Finance\Shared\Ai\FinanceAiFailure;

/**
 * The two AI drafts (`app.py` routes 51 and 54): a question for the vendor about an invoice, and a reply to a scanned email, each
 * written, or revised, from the person's instruction. The model call is {@see ApAiService}; this builds what it is given from the
 * record and shapes the answer `{subject, body, to}`.
 *
 * Nothing is sent or stored, and no audit event is written, so drafting works while sending is switched off. An unknown or
 * out-of-scope record is a 404, an empty instruction a 422 with the source's words, a provider failure a 502 with its message.
 */
final class ApDraftMailService
{
    public function __construct(
        private readonly ApInvoiceStore $invoices,
        private readonly ApEmailService $emails,
        private readonly ApAiService $ai,
    ) {
    }

    /**
     * `POST /invoices/{id}/email-draft`.
     *
     * @param array<string, mixed> $payload `{instruction, previous_draft}`
     * @return array{subject: string, body: string, to: string}
     */
    public function draftVendorEmail(ApActor $actor, string $invoiceId, array $payload): array
    {
        $invoice = $this->invoices->find($invoiceId) ?? throw new ApNotFound(ApMailService::NOT_FOUND);
        $actor->mailboxScope->requireRecord($invoice->mailbox, ApMailService::NOT_FOUND);
        $instruction = self::instruction($payload, ApAiService::VENDOR_INSTRUCTION_REQUIRED);

        $draft = $this->ask(fn (): ApDraft => $this->ai->draftVendorEmail(self::forVendor($invoice), $instruction, self::previous($payload)));

        // The source: "Question about invoice <invoice_no>" when the model gave no subject.
        $subject = trim($draft->subject) !== '' ? $draft->subject : 'Question about invoice ' . $invoice->invoiceNo;

        return ['subject' => $subject, 'body' => $draft->body, 'to' => $draft->to];
    }

    /**
     * `POST /emails/{id}/email-draft`.
     *
     * @param array<string, mixed> $payload `{instruction, previous_draft}`
     * @return array{subject: string, body: string, to: string}
     */
    public function draftReply(ApActor $actor, string $emailId, array $payload): array
    {
        $email = $this->emails->get($actor, $emailId);
        $instruction = self::instruction($payload, ApAiService::REPLY_INSTRUCTION_REQUIRED);

        $draft = $this->ask(fn (): ApDraft => $this->ai->draftReply(self::forReply($email), $instruction, self::previous($payload)));

        // The source: the original subject, with `Re: ` unless it already starts with `re:` (any case), when the model gave none.
        $subject = trim($draft->subject) !== '' ? $draft->subject : (stripos(ltrim($email->subject), 're:') === 0 ? $email->subject : 'Re: ' . $email->subject);

        return ['subject' => $subject, 'body' => $draft->body, 'to' => $draft->to];
    }

    /**
     * @param callable(): ApDraft $call
     */
    private function ask(callable $call): ApDraft
    {
        try {
            return $call();
        } catch (FinanceAiFailure $failure) {
            throw new ApUpstream($failure->getMessage(), ApUpstream::AI, $failure);
        } catch (InvalidArgumentException $empty) {
            throw new ApInvalid($empty->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function instruction(array $payload, string $message): string
    {
        $instruction = is_string($payload['instruction'] ?? null) ? trim($payload['instruction']) : '';
        if ($instruction === '') {
            throw new ApInvalid($message);
        }

        return $instruction;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function previous(array $payload): string
    {
        return is_string($payload['previous_draft'] ?? null) ? $payload['previous_draft'] : '';
    }

    /** The fields the source's prompt reads; the currency is `AUD` when empty and the total goes in as a number. */
    private static function forVendor(ApInvoice $invoice): ApDraftInvoice
    {
        return new ApDraftInvoice(
            $invoice->vendor,
            $invoice->invoiceNo,
            $invoice->invoiceDate,
            $invoice->poNumber,
            $invoice->currency !== '' ? $invoice->currency : 'AUD',
            $invoice->totalAmount,
            $invoice->subject,
            $invoice->vendorEmail,
            $invoice->sender,
        );
    }

    private static function forReply(ApEmail $email): ApDraftEmail
    {
        return new ApDraftEmail($email->senderName, $email->senderEmail, $email->subject, $email->bodyPreview);
    }
}
