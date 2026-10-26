<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Contract;

use TriCab\Core\Finance\Ap2\Ai\Ap2MailClassification;
use TriCab\Core\Finance\Ap2\Ai\Ap2SupplierRef;

/**
 * Reads one incoming message and says what it is and who sent it. Called by the mail sync for every NEW message.
 *
 * The supplier match by sender address or domain is done in PHP BEFORE this is asked; the answer's `supplierId` is
 * used only when that match found nothing, and only if it is one of the ids in `$suppliers`.
 * Must not throw for an unreadable message: return {@see Ap2MailClassification::none()}. May throw
 * {@see \TriCab\Core\Finance\Ap2\Ai\Ap2AiUnavailable}; the sync then stores the message unclassified.
 */
interface Ap2MailClassifier
{
    /**
     * @param list<string> $attachmentNames
     * @param list<Ap2SupplierRef> $suppliers
     */
    public function classify(string $subject, string $from, string $bodyText, array $attachmentNames, array $suppliers): Ap2MailClassification;
}
