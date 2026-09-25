<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Service;

/**
 * Which training modules HR signs off after the assessment is passed (5.4).
 *
 * Ported from the source's `requires_review = (document_reference =
 * 'HR-DOC-009-V010')`: the Corporate WHSE Induction alone. Its paper sheet has
 * a supervisor block — competent / not yet competent, the supervisor's or
 * manager's feedback, received by HR, and the date — so a pass is the starter
 * handing their work in, not the end of it. HR accepts it with that feedback,
 * and the sheet is stamped again with it.
 *
 * Keyed by the module's slug, as the assessment sheets are. A slug is fixed
 * when the module is created, so this cannot drift from the sheet it names.
 *
 * Pure: no database, no request, no clock.
 */
final class TrainingReviewPolicy
{
    /** @var list<string> */
    private const SIGNED_OFF_BY_HR = ['corporate-whse-induction'];

    public static function requiresSignOff(string $moduleSlug): bool
    {
        return in_array($moduleSlug, self::SIGNED_OFF_BY_HR, true);
    }
}
