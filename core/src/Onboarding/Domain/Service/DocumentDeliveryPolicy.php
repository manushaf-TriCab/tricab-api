<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Service;

use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;

/**
 * How a stored document reaches the person entitled to it.
 *
 * ## The decision
 *
 * Owner decision, 21 September 2026, answering open question 7: **take the
 * recommendation.** Payroll and restricted-HR documents are streamed through an
 * authenticated endpoint and every read is audited. Everything else may be
 * handed out as a short-lived signed URL, following the expense precedent.
 *
 * ## Why the two are not the same thing
 *
 * `AzureBlobExpenseAttachmentStore` hands the browser a short-lived SAS URL, and
 * for a receipt photo that is a reasonable trade. A SAS URL is a **bearer
 * capability**: it works for whoever holds it, it does not know who asked, it
 * lands in browser history and in any log that records query strings, and once
 * issued it cannot be tied to a person. For a medical questionnaire or a form
 * carrying a bank account and a payslip PIN, "somebody with this link opened it"
 * is not an answer anybody can give a regulator.
 *
 * Streaming costs API bandwidth and is the less conventional choice inside this
 * codebase. What it buys is that each read is authorised at the moment it
 * happens and written to `audit.OnboardingDocumentAccessEvents` with an actor.
 *
 * ## Fail closed
 *
 * An unrecognised or unclassified document is streamed, not signed. A document
 * whose class nobody recorded is more likely to be the sensitive one than the
 * harmless one — the harmless ones are the ones people remember to classify —
 * and the cost of being wrong in this direction is bandwidth, while the cost of
 * being wrong in the other is an unattributable disclosure.
 *
 * Pure: no blob client, no request, no clock.
 */
final class DocumentDeliveryPolicy
{
    /** Streamed through an authenticated API endpoint; every read audited. */
    public const STREAM = 'stream';

    /** Short-lived signed URL, following the expense precedent. */
    public const SIGNED_URL = 'signed_url';

    /**
     * @return self::STREAM|self::SIGNED_URL
     */
    public static function modeFor(DataClass $class): string
    {
        return self::mustBeStreamed($class) ? self::STREAM : self::SIGNED_URL;
    }

    public static function mustBeStreamed(DataClass $class): bool
    {
        if ($class->isRestricted()) {
            return true;
        }

        // C5 means "generated document, class not recorded". A document's class
        // is its content's class, so a correctly classified payroll PDF arrives
        // here as C3. Reaching this line at all means somebody did not classify
        // it, which is the case to be careful about.
        return $class->equals(DataClass::generatedDocument());
    }

    /**
     * Whether a read of this class must be written to
     * `audit.OnboardingDocumentAccessEvents`.
     *
     * Identical to {@see mustBeStreamed()} today, and named separately because
     * they are two different obligations that happen to coincide: one is how the
     * bytes travel, the other is what gets recorded. A future signed URL that
     * could be attributed would separate them.
     */
    public static function mustBeAudited(DataClass $class): bool
    {
        return self::mustBeStreamed($class);
    }

    /**
     * Whether a signed URL may be issued at all.
     *
     * Stated as its own question because it is the one a caller asks when it is
     * about to do something irreversible: issuing a bearer capability cannot be
     * taken back once the link exists.
     */
    public static function maySignUrl(DataClass $class): bool
    {
        return !self::mustBeStreamed($class);
    }
}
