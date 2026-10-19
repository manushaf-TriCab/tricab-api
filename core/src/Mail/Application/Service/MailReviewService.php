<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\Service;

use TriCab\Core\Mail\Application\DTO\MailBlocker;
use TriCab\Core\Mail\Application\DTO\MailParty;
use TriCab\Core\Mail\Application\DTO\NewDecision;
use TriCab\Core\Mail\Application\DTO\NewLink;
use TriCab\Core\Mail\Application\DTO\ReviewRequest;
use TriCab\Core\Mail\Application\DTO\ThreadDetail;
use TriCab\Core\Mail\Contract\MailStore;
use TriCab\Core\Mail\Contract\RfqDraftCreator;
use TriCab\Core\Mail\Domain\Exception\MailActionBlocked;
use TriCab\Core\Mail\Domain\Exception\MailActionForbidden;
use TriCab\Core\Mail\Domain\MailActor;
use TriCab\Core\Mail\Domain\MailVocabulary as V;
use TriCab\Core\Mail\Domain\Service\MailAccessPolicy;
use TriCab\Core\Shared\Time\Clock;

/**
 * `POST /threads/{id}/review`: marks a label, creates a draft, ignores, or marks read or unread; and
 * `DELETE /threads/{id}`, which takes the conversation out of the inbox.
 *
 * The route holds `MAIL.ACCESS` (reading and unreading need no more); every other action needs
 * `MAIL.REVIEW`, which is checked here. Then {@see MailReviewGuard} decides, and a refusal is
 * {@see MailActionBlocked} with nothing changed. The answer is the thread as it is afterwards.
 */
final class MailReviewService
{
    public function __construct(
        private readonly MailStore $store,
        private readonly MailInboxService $inbox,
        private readonly RfqDraftCreator $drafts,
        private readonly Clock $clock,
    ) {
    }

    public function review(MailActor $actor, string $threadId, ReviewRequest $request): ThreadDetail
    {
        $detail = $this->inbox->thread($actor, $threadId);
        $isReadMark = in_array($request->action, [V::ACTION_MARK_READ, V::ACTION_MARK_UNREAD], true);
        if (!$isReadMark && !MailAccessPolicy::canReview($actor)) {
            throw new MailActionForbidden('You do not have permission to review mail.');
        }

        $blocked = $detail->blockers[$request->action] ?? [];
        if ($blocked !== []) {
            throw new MailActionBlocked($blocked);
        }
        // An action neither allowed nor explained cannot happen for a reviewer today; refuse rather than guess.
        if (!$isReadMark && !in_array($request->action, $detail->allowedActions, true)) {
            throw new MailActionBlocked([new MailBlocker(V::BLOCKER_NOT_UNCERTAIN, 'That action is not available for this conversation.')]);
        }

        $summary = $detail->record->summary;
        $by = new MailParty($actor->name, $actor->email);
        $now = $this->clock->now();
        switch ($request->action) {
            case V::ACTION_MARK_READ:
            case V::ACTION_MARK_UNREAD:
                $this->store->markRead($threadId, $request->action === V::ACTION_MARK_READ);
                break;
            case V::ACTION_CONFIRM:
            case V::ACTION_CORRECT:
                $confirm = $request->action === V::ACTION_CONFIRM;
                $this->store->recordDecision($threadId, new NewDecision(
                    $detail->record->assessment->id,
                    $confirm ? 'confirm' : 'correct',
                    $confirm ? $detail->record->assessment->label : (string) $request->label,
                    $actor->userId,
                    $by,
                    $request->note,
                    $now,
                ));
                break;
            case V::ACTION_CREATE_DRAFT:
                // The draft is made first: if it cannot be, nothing was recorded and the thread stays open.
                $draft = $this->drafts->createFromThread($actor, $threadId, $summary->subject);
                $this->store->recordDraft($threadId, new NewLink($draft->publicId, $draft->reference, $draft->title, $actor->userId, $now), $by);
                break;
            case V::ACTION_IGNORE:
                $this->store->recordIgnore($threadId, $by, $actor->userId, $request->note, $now);
                break;
        }

        return $this->inbox->thread($actor, $threadId);
    }

    /**
     * `DELETE /threads/{id}`: takes the conversation out of the inbox for everyone. Needs `MAIL.REVIEW`,
     * whatever state the thread is in. Nothing is deleted in Exchange, and a new message in the
     * conversation brings it back (see {@see MailStore::deleteThread()}).
     */
    public function delete(MailActor $actor, string $threadId): void
    {
        if (!MailAccessPolicy::canReview($actor)) {
            throw new MailActionForbidden('You do not have permission to delete mail.');
        }
        // Not found (or already deleted) is a 404, as for every other read of the thread.
        $this->inbox->thread($actor, $threadId);
        $this->store->deleteThread($threadId, new MailParty($actor->name, $actor->email), $actor->userId, $this->clock->now());
    }
}
