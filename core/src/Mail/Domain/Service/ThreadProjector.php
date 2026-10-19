<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Domain\Service;

use TriCab\Core\Mail\Application\DTO\AssessmentView;
use TriCab\Core\Mail\Application\DTO\DecisionView;
use TriCab\Core\Mail\Application\DTO\MailParty;
use TriCab\Core\Mail\Application\DTO\MessageView;
use TriCab\Core\Mail\Application\DTO\ThreadProjection;
use TriCab\Core\Mail\Application\DTO\ThreadSummary;

/**
 * Works out a thread's summary from its messages, assessments and decisions. Pure, and the one place
 * the rules live: the Azure SQL store writes the result into `mail.threads` and the in-memory store
 * serves it, so the two cannot disagree.
 *
 *  - The thread's assessment is the latest result for its newest assessed message. Until there is one the
 *    thread is not `classified` and is in no list.
 *  - The latest decision wins. History stays in the tables.
 *  - The sender and preview are the latest message not sent from the mailbox itself, so a reply from
 *    melbourne@ does not replace the customer on the row (the latest message otherwise).
 *  - Unread means any message is unread; `hasAttachments` any listed (non-inline) attachment.
 */
final class ThreadProjector
{
    /**
     * @param list<MessageView> $messages
     * @param list<AssessmentView> $assessments of any of the thread's messages
     * @param list<DecisionView> $decisions
     */
    public static function project(
        string $threadId,
        string $mailboxId,
        string $mailboxAddress,
        string $subject,
        string $reviewState,
        array $messages,
        array $assessments,
        array $decisions,
    ): ThreadProjection {
        $ordered = $messages;
        usort($ordered, static fn (MessageView $a, MessageView $b): int => $a->receivedAt <=> $b->receivedAt);
        $unread = 0;
        foreach ($ordered as $message) {
            if (!$message->isRead) {
                ++$unread;
            }
        }
        $assessment = self::currentAssessment($ordered, $assessments);
        $decision = self::currentDecision($decisions);
        if ($ordered === []) {
            throw new \LogicException('A thread is projected from at least one message.');
        }
        $latest = $ordered[count($ordered) - 1];
        $inbound = $latest;
        for ($i = count($ordered) - 1; $i >= 0; --$i) {
            if (strtolower($ordered[$i]->from->address) !== strtolower($mailboxAddress)) {
                $inbound = $ordered[$i];
                break;
            }
        }
        $hasAttachments = false;
        foreach ($ordered as $message) {
            $hasAttachments = $hasAttachments || $message->attachments !== [];
        }

        return new ThreadProjection(
            new ThreadSummary(
                $threadId,
                $mailboxId,
                $subject,
                new MailParty($inbound->from->name, $inbound->from->address),
                $inbound->preview,
                $latest->receivedAt,
                count($ordered),
                $hasAttachments,
                $unread > 0,
                $assessment?->label ?? 'uncertain',
                $assessment?->confidence ?? 0.0,
                $decision?->label ?? $assessment?->label ?? 'uncertain',
                $decision !== null,
                $assessment?->skipped ?? false,
                $reviewState,
            ),
            $assessment !== null,
            $assessment?->id,
            $decision?->id,
            $unread,
        );
    }

    /**
     * @param list<MessageView> $orderedMessages oldest first
     * @param list<AssessmentView> $assessments
     */
    public static function currentAssessment(array $orderedMessages, array $assessments): ?AssessmentView
    {
        for ($i = count($orderedMessages) - 1; $i >= 0; --$i) {
            $best = null;
            foreach ($assessments as $candidate) {
                if ($candidate->messageId === $orderedMessages[$i]->id && ($best === null || $candidate->assessedAt >= $best->assessedAt)) {
                    $best = $candidate;
                }
            }
            if ($best !== null) {
                return $best;
            }
        }

        return null;
    }

    /** @param list<DecisionView> $decisions */
    public static function currentDecision(array $decisions): ?DecisionView
    {
        $best = null;
        foreach ($decisions as $candidate) {
            if ($best === null || $candidate->decidedAt >= $best->decidedAt) {
                $best = $candidate;
            }
        }

        return $best;
    }
}
