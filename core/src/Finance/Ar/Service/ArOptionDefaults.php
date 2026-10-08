<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Service;

use DateTimeImmutable;
use TriCab\Core\Finance\Ar\Domain\ArOption;

/**
 * The Category and Method lists a fresh install starts with: `server/option_defaults.py` at
 * `6b54894`, word for word, in the same order. An empty list is seeded from these (and a list
 * with rows is never touched, so the Settings page's edits survive). `description` is the
 * criterion the classifier is given for each option. `other`, `phone` and `email` are the
 * built-ins (`system`): they cannot be archived or removed.
 */
final class ArOptionDefaults
{
    public const FALLBACK_CATEGORY = 'other';
    public const FALLBACK_METHOD = 'email';

    /** @var list<array{0: string, 1: string, 2: string, 3: string, 4?: bool}> value, label, colour, description, system */
    private const CATEGORIES = [
        ['remittance_advice', 'Remittance / Payment', 'teal', 'Notification that a payment has been made or scheduled: remittance advice, EFT advice or payment notification, often with the detail in an attachment. Reports a payment rather than asking for anything'],
        ['credit_application', 'Credit Application', 'blue', 'Customer applying for a new credit account or credit terms'],
        ['release_customer_orders', 'Release Orders', 'emerald', 'Request to release or ship customer orders on hold'],
        ['exceeding_credit_limits', 'Credit Limit / Terms', 'amber', 'Exceeding credit limits or payment-term exceptions'],
        ['invoice_statement_requests', 'Invoice / Statement', 'violet', 'Requesting, amending or querying invoices or statements'],
        ['credit_notes', 'Credit Note', 'pink', 'Requesting a credit note'],
        ['overdue_collections', 'Overdue / Collections', 'rose', 'Overdue accounts, collections or payment follow-up'],
        ['compliance_questionnaire', 'Compliance / Supplier', 'indigo', 'A customer or their procurement platform asking TriCab to complete supplier onboarding, compliance, modern slavery or code-of-conduct paperwork'],
        ['internal_sales_query', 'Internal / Sales', 'cyan', 'An internal thread between TriCab staff, or one they have forwarded in, discussing an order, product or claim rather than direct correspondence from a customer'],
        ['marketing_no_action', 'Marketing', 'neutral', 'Marketing, newsletters, webinar invitations, promotions or vendor advertising with no accounts-receivable content'],
        ['general_inquiry', 'General Inquiry', 'slate', 'General account-related enquiry'],
        ['other', 'Other', 'gray', 'Does not fit any of the above', true],
    ];

    /** @var list<array{0: string, 1: string, 2: string, 3: string, 4?: bool}> */
    private const METHODS = [
        ['phone', 'Phone', 'amber', 'Complex, sensitive, disputed or urgent — better handled by voice', true],
        ['email', 'Email', 'blue', 'Routine, documentary, or needs a written record', true],
    ];

    /**
     * @return list<ArOption> the defaults of one list, in order, ready to insert
     */
    public static function options(string $listName, DateTimeImmutable $now): array
    {
        $rows = match ($listName) {
            ArOption::LIST_CATEGORY => self::CATEGORIES,
            ArOption::LIST_METHOD => self::METHODS,
            default => [],
        };
        $out = [];
        foreach ($rows as $order => $row) {
            $out[] = new ArOption($listName, $row[0], $row[1], $row[3], $row[2] !== '' ? $row[2] : 'slate', $order, true, $row[4] ?? false, $now, $now);
        }

        return $out;
    }
}
