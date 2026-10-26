<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2\Ai;

use TriCab\Core\Finance\Ap2\Ai\Ap2MailClassification;
use TriCab\Core\Finance\Ap2\Contract\Ap2MailClassifier;

/**
 * AI not connected: a RULE-BASED guess of the category from the subject and the attachment names, so the inbox is not
 * all "Other" while AI is off. It never names a supplier (the sender-domain match is done before, in PHP) and its
 * confidence is always 0.3; the summary says it is a rule, not a model.
 *
 * First match wins, most sensitive first:
 *   "bank details" / "change of bank" / "new bank" / "bsb" ......... Bank detail change
 *   "credit" ........................................................ Credit note
 *   "statement" ..................................................... Supplier statement
 *   "remittance" / "payment" ........................................ Payment enquiry
 *   a PDF attached and "invoice" / "tax invoice" ................... Supplier invoice
 *   anything else ................................................... Other
 */
final class NullAp2MailClassifier implements Ap2MailClassifier
{
    public const CONFIDENCE = 0.3;

    public const SUMMARY_PREFIX = 'Rule-based guess (AI is off): ';

    public function classify(string $subject, string $from, string $bodyText, array $attachmentNames, array $suppliers): Ap2MailClassification
    {
        $haystack = mb_strtolower($subject . ' ' . implode(' ', $attachmentNames));
        $has = static fn (string ...$words): bool => array_filter($words, static fn (string $w): bool => str_contains($haystack, $w)) !== [];
        $pdf = array_filter($attachmentNames, static fn (string $n): bool => str_ends_with(mb_strtolower(trim($n)), '.pdf')) !== [];

        [$category, $why] = match (true) {
            $has('bank details', 'bank detail', 'change of bank', 'new bank', 'bsb') => ['Bank detail change', 'the subject mentions bank details'],
            $has('credit') => ['Credit note', 'the subject or a file name says credit'],
            $has('statement') => ['Supplier statement', 'the subject or a file name says statement'],
            $has('remittance', 'payment') => ['Payment enquiry', 'the subject says remittance or payment'],
            $pdf && $has('invoice') => ['Supplier invoice', 'a PDF is attached and the subject or file name says invoice'],
            default => ['Other', 'no keyword matched'],
        };

        return new Ap2MailClassification($category, null, self::CONFIDENCE, self::SUMMARY_PREFIX . $category . ' (' . $why . ').');
    }
}
