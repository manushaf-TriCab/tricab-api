<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\Service;

use TriCab\Core\Mail\Application\DTO\AssessmentView;
use TriCab\Core\Mail\Application\DTO\MailBlocker;
use TriCab\Core\Mail\Application\DTO\ThreadSummary;
use TriCab\Core\Mail\Domain\MailActor;
use TriCab\Core\Mail\Domain\MailVocabulary as V;
use TriCab\Core\Mail\Domain\Service\MailAccessPolicy;

/**
 * Which actions a person may take on a thread, and why the others are refused (plan I4.2). **This is
 * the authority**: the detail route returns its answer, the review route refuses by it, and the
 * client only shows the reasons.
 *
 * `create_draft` and `ignore` are for the classifier's `uncertain` threads that are still `open`:
 * eligibility follows the classifier's label, so deciding the label (even to `rfq`) does not make the
 * thread ineligible. Until a person has marked the label right or wrong they are blocked with
 * `undecided_label`; a thread whose assessment flagged text aimed at an assistant is blocked with
 * `injection` for both. Marking a label is always open to a reviewer, even after the thread has been
 * actioned, because the marks are the labelled set (RFQ M5); a pre-filter skip has no label to
 * confirm, only to correct.
 */
final class MailReviewGuard
{
    /**
     * @return array{allowed: list<string>, blockers: array<string, list<MailBlocker>>}
     */
    public static function evaluate(ThreadSummary $thread, AssessmentView $assessment, MailActor $actor): array
    {
        $allowed = [$thread->unread ? V::ACTION_MARK_READ : V::ACTION_MARK_UNREAD];
        $blockers = [];
        if (!MailAccessPolicy::canReview($actor)) {
            return ['allowed' => $allowed, 'blockers' => $blockers];
        }

        if ($assessment->skipped) {
            $blockers[V::ACTION_CONFIRM] = [new MailBlocker(
                V::BLOCKER_NOT_UNCERTAIN,
                'The classifier did not read this message, so there is no label to confirm. Correct it instead.',
            )];
        } else {
            $allowed[] = V::ACTION_CONFIRM;
        }
        $allowed[] = V::ACTION_CORRECT;

        foreach ([V::ACTION_CREATE_DRAFT, V::ACTION_IGNORE] as $action) {
            if ($thread->reviewState !== V::STATE_OPEN) {
                $blockers[$action] = [new MailBlocker(V::BLOCKER_ALREADY_ACTIONED, 'This conversation has already been actioned.')];
                continue;
            }
            if ($assessment->label !== V::LABEL_UNCERTAIN) {
                $blockers[$action] = [new MailBlocker(V::BLOCKER_NOT_UNCERTAIN, 'Only conversations the classifier was unsure about can be actioned here.')];
                continue;
            }
            $allowed[] = $action;
            $found = [];
            if (!$thread->decided) {
                $found[] = new MailBlocker(V::BLOCKER_UNDECIDED_LABEL, 'Mark the label as right or wrong first.');
            }
            if ($assessment->injectionSuspected) {
                $found[] = new MailBlocker(V::BLOCKER_INJECTION, 'The message contains text addressed to an assistant. Read it before acting.');
            }
            if ($found !== []) {
                $blockers[$action] = $found;
            }
        }

        return ['allowed' => $allowed, 'blockers' => $blockers];
    }
}
