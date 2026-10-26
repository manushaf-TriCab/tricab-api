<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Contract;

use TriCab\Core\Finance\Ap2\Ai\Ap2MailContext;
use TriCab\Core\Finance\Ap2\Ai\Ap2SupplierRef;

/**
 * Writes a reply to a supplier email (plain text). Called by `POST /mail/{id}/draft`; the draft is returned to the
 * person, never saved or sent by the server.
 *
 * `$invoice` is the linked invoice exactly as the API presents it (the contract's Invoice record), or null.
 * `$validationIssues` is that invoice's `validation` list (empty when there is none).
 * `$instruction` is the person's own steer ("ask for the PO number"), or null.
 * Throws {@see \TriCab\Core\Finance\Ap2\Ai\Ap2AiUnavailable} when it cannot write one.
 */
interface Ap2DraftWriter
{
    /**
     * @param array<string, mixed>|null $invoice
     * @param list<string> $validationIssues
     */
    public function draft(Ap2MailContext $mail, ?Ap2SupplierRef $supplier, ?array $invoice, array $validationIssues, ?string $instruction): string;
}
