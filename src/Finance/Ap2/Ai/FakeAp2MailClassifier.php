<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2\Ai;

use TriCab\Core\Finance\Ap2\Ai\Ap2MailClassification;
use TriCab\Core\Finance\Ap2\Contract\Ap2MailClassifier;

/**
 * A classifier without a model, for tests and local development: keywords in the subject and body, as the prototype's
 * seed mail reads, and no supplier (the sync's own sender match supplies that). `$next` overrides the answer;
 * `$failWith` makes the call fail.
 */
final class FakeAp2MailClassifier implements Ap2MailClassifier
{
    public ?Ap2MailClassification $next = null;

    public ?\Throwable $failWith = null;

    /** @var list<array{subject: string, from: string, suppliers: int}> */
    public array $calls = [];

    private const KEYWORDS = [
        'Bank detail change' => '/\b(bank (account|detail|details)|new account|change of bank|bsb)\b/i',
        'Credit note' => '/\bcredit note\b|\bcn-\d/i',
        'Supplier statement' => '/\bstatement\b/i',
        'Missing PO / receipt' => '/\b(missing|no) (po|purchase order|receipt)\b/i',
        'Payment enquiry' => '/\b(payment|remittance|when will|overdue|paid)\b/i',
        'Supplier invoice' => '/\binvoice\b/i',
    ];

    public function classify(string $subject, string $from, string $bodyText, array $attachmentNames, array $suppliers): Ap2MailClassification
    {
        $this->calls[] = ['subject' => $subject, 'from' => $from, 'suppliers' => count($suppliers)];
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
        if ($this->next !== null) {
            return $this->next;
        }
        $text = $subject . "\n" . $bodyText;
        $category = 'Other';
        foreach (self::KEYWORDS as $candidate => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $category = $candidate;
                break;
            }
        }

        return new Ap2MailClassification($category, null, $category === 'Other' ? 0.3 : 0.6, Ap2Fence::line($subject !== '' ? $subject : 'No subject', 160));
    }
}
