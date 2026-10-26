<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2\Ai;

use TriCab\Core\Finance\Ap2\Ai\Ap2AiUnavailable;
use TriCab\Core\Finance\Ap2\Contract\Ap2DraftWriter;

/** AI not connected: refuses (503 FINANCE_AP2_AI_UNAVAILABLE). */
final class NullAp2DraftWriter implements Ap2DraftWriter
{
    public function draft(\TriCab\Core\Finance\Ap2\Ai\Ap2MailContext $mail, ?\TriCab\Core\Finance\Ap2\Ai\Ap2SupplierRef $supplier, ?array $invoice, array $validationIssues, ?string $instruction): string
    {
        throw new Ap2AiUnavailable('AI drafting is not connected yet');
    }
}
