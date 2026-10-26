<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2\Ai;

use TriCab\Core\Finance\Ap2\Ai\Ap2MailContext;
use TriCab\Core\Finance\Ap2\Ai\Ap2SupplierRef;
use TriCab\Core\Finance\Ap2\Contract\Ap2DraftWriter;

/**
 * A draft writer without a model: the prototype's seeded drafts (`data.js`) and its supplier clarification
 * (`credit-draft` in `app.js`), word for word where the prototype has them. For tests and local development.
 */
final class FakeAp2DraftWriter implements Ap2DraftWriter
{
    public ?\Throwable $failWith = null;

    /** @var list<array{mailId: string, issues: list<string>, instruction: ?string}> */
    public array $calls = [];

    public function draft(Ap2MailContext $mail, ?Ap2SupplierRef $supplier, ?array $invoice, array $validationIssues, ?string $instruction): string
    {
        $this->calls[] = ['mailId' => $mail->id, 'issues' => $validationIssues, 'instruction' => $instruction];
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        return self::template($mail, $invoice, $validationIssues);
    }

    /**
     * @param array<string, mixed>|null $invoice
     * @param list<string>              $issues
     */
    public static function template(Ap2MailContext $mail, ?array $invoice, array $issues): string
    {
        $number = is_array($invoice) && is_string($invoice['number'] ?? null) ? $invoice['number'] : null;
        if ($number !== null && $issues !== []) {
            return 'Thank you for invoice ' . $number . '. Our review identified: ' . implode('; ', $issues)
                . '. Please provide the supporting PO, receipt evidence or applicable credit note so we can complete our review.';
        }
        $creditRef = is_array($invoice) && is_string($invoice['creditRef'] ?? null) && $invoice['creditRef'] !== '' ? ' ' . $invoice['creditRef'] : '';

        return match ($mail->category) {
            'Bank detail change' => 'Thank you for your email. Bank detail changes require independent verification through our existing trusted contact. No account details have been changed.',
            'Supplier statement' => 'Thank you for your statement. We will reconcile it against our posted invoices and contact you regarding any differences.',
            'Credit note' => 'Thank you for credit note' . $creditRef . '. We will verify it against the invoice and receipt evidence before updating the record.',
            default => $number !== null
                ? 'Thank you for sending invoice ' . $number . '. We have received it and will review it against our purchase and receipt records. We will contact you if any information is needed.'
                : 'Thank you for your email regarding ' . $mail->subject . '. We are reviewing the supporting information and will contact you with an update.',
        };
    }
}
