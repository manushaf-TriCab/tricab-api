<?php

declare(strict_types=1);

namespace TriCab\Api\Quotegen\Application;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use TriCab\Api\Assistant\Infrastructure\Foundry\FoundryProviderUnavailable;
use TriCab\Core\Rfq\Domain\ValueObject\RfqActor;
use TriCab\Core\Rfq\Module;

/**
 * One RFQ-bound assistant chat: the shared conversation, and a turn of it.
 *
 * Who is asking, which RFQ and what they may do all come from the arguments the
 * action resolved on the server; the body of the request contributes only the
 * message text and the revision the person is looking at.
 */
final class QuotegenChatService
{
    private const MAX_MESSAGE = 4_000;
    private const MAX_HISTORY_MESSAGES = 20;

    public function __construct(
        private readonly QuotegenConversationRepository $conversations,
        private readonly QuotegenBudgetLedger $ledger,
        private readonly QuotegenAgent $agent,
        private readonly QuotegenQuoteGateway $quotes,
        private readonly int $budgetLimitMicros,
    ) {
    }

    /** Whether this actor can see the RFQ at all (a 404 otherwise). */
    public function canSee(RfqActor $actor, string $actorEmail, string $rfqPublicId): bool
    {
        return $this->quotes->snapshot(new QuotegenTurnContext($actor, $actorEmail, $rfqPublicId, '', '', 0)) !== null;
    }

    public function mayUse(RfqActor $actor): bool
    {
        return $actor->can(Module::PERMISSION_DRAFT_READ);
    }

    /**
     * @return array{conversationId: string, messages: list<array<string, mixed>>, budget: array<string, mixed>}
     */
    public function history(RfqActor $actor, string $rfqPublicId): array
    {
        $conversation = $this->conversations->conversationFor($rfqPublicId);
        $pricing = $actor->can(Module::PERMISSION_PRICING_VIEW);

        return [
            'conversationId' => $conversation->id,
            'messages' => array_map(
                static fn (QuotegenMessage $m): array => $m->forReader($pricing)->toArray(),
                $this->conversations->messages($conversation->id),
            ),
            'budget' => $this->ledger->summary($rfqPublicId, $this->budgetLimitMicros)->toArray(),
        ];
    }

    /**
     * Runs one turn, reporting it as SSE events through `$emit`. Never throws:
     * every failure ends in a terminal `error` event.
     *
     * @param callable(string, array<string, mixed>): void $emit
     */
    public function turn(RfqActor $actor, string $actorEmail, string $actorName, string $rfqPublicId, mixed $message, mixed $revision, callable $emit): void
    {
        $messageId = self::uuid();
        $emit('ack', ['messageId' => $messageId]);

        try {
            $this->runTurn($actor, $actorEmail, $actorName, $rfqPublicId, $message, $revision, $messageId, $emit);
        } catch (Throwable) {
            $emit('error', ['code' => 'provider_unavailable', 'message' => 'The assistant is temporarily unavailable.']);
        }
    }

    /** @param callable(string, array<string, mixed>): void $emit */
    private function runTurn(RfqActor $actor, string $actorEmail, string $actorName, string $rfqPublicId, mixed $message, mixed $revision, string $messageId, callable $emit): void
    {
        if (!$this->mayUse($actor)) {
            $emit('error', ['code' => 'forbidden', 'message' => 'You do not have access to this quote.']);

            return;
        }
        $text = is_string($message) ? trim($message) : '';
        if ($text === '' || mb_strlen($text) > self::MAX_MESSAGE) {
            $emit('error', ['code' => 'invalid', 'message' => sprintf('Write a message of up to %d characters.', self::MAX_MESSAGE)]);

            return;
        }
        if (!is_int($revision) || $revision < 1) {
            $emit('error', ['code' => 'invalid', 'message' => 'revision must be the revision you are looking at.']);

            return;
        }
        $probe = new QuotegenTurnContext($actor, $actorEmail, $rfqPublicId, '', $messageId, $revision);
        $snapshot = $this->quotes->snapshot($probe);
        if ($snapshot === null) {
            $emit('error', ['code' => 'invalid', 'message' => 'This quote could not be found.']);

            return;
        }
        // Before any model call, and before the message is kept.
        if ($snapshot['revision'] !== $revision) {
            $emit('error', ['code' => 'revision_conflict', 'message' => 'The quote has changed since you opened it. Reload it and send your message again.']);

            return;
        }
        if ($this->ledger->summary($rfqPublicId, $this->budgetLimitMicros)->exhausted()) {
            $emit('error', ['code' => 'budget_exhausted', 'message' => 'The assistant budget for this quote is used up.']);

            return;
        }

        $conversation = $this->conversations->conversationFor($rfqPublicId);
        $pricing = $actor->can(Module::PERMISSION_PRICING_VIEW);
        $this->conversations->append($conversation->id, new QuotegenMessage(
            $messageId,
            'user',
            $actor->userPublicId,
            $actorName,
            $text,
            [],
            null,
            self::now(),
        ));
        $context = new QuotegenTurnContext($actor, $actorEmail, $rfqPublicId, $conversation->id, $messageId, $revision);

        try {
            $result = $this->agent->run($context, $this->modelHistory($conversation->id, $pricing, $revision), $emit);
        } catch (QuotegenBudgetExhausted) {
            $this->note($conversation->id, 'The assistant stopped because the budget for this quote is used up. Anything it had already saved is kept.');
            $emit('error', ['code' => 'budget_exhausted', 'message' => 'The assistant budget for this quote is used up.']);

            return;
        } catch (FoundryProviderUnavailable) {
            $this->note($conversation->id, 'The assistant could not finish this request. Anything it had already saved is kept; check the quote.');
            $emit('error', ['code' => 'provider_unavailable', 'message' => 'The assistant is temporarily unavailable.']);

            return;
        }

        $reply = new QuotegenMessage(
            self::uuid(),
            'assistant',
            null,
            null,
            $result->text,
            $result->events,
            $result->proposedSyncRevision,
            self::now(),
            $result->containsPricing,
        );
        $this->conversations->append($conversation->id, $reply);
        $emit('done', [
            'message' => $reply->forReader($pricing)->toArray(),
            'budget' => $this->ledger->summary($rfqPublicId, $this->budgetLimitMicros)->toArray(),
        ]);
    }

    private function note(string $conversationId, string $text): void
    {
        $this->conversations->append($conversationId, new QuotegenMessage(self::uuid(), 'event', null, null, $text, [], null, self::now()));
    }

    /**
     * The conversation as the model reads it: people's messages named, replies the
     * reader may not see (pricing) withheld, roles alternating.
     *
     * @return list<array{role: string, content: string}>
     */
    private function modelHistory(string $conversationId, bool $pricing, int $revision): array
    {
        $recent = array_slice($this->conversations->messages($conversationId), -self::MAX_HISTORY_MESSAGES);
        $out = [];
        foreach ($recent as $message) {
            if ($message->role === 'event') {
                continue;
            }
            $message = $message->forReader($pricing);
            $role = $message->role === 'assistant' ? 'assistant' : 'user';
            $content = $role === 'user' && $message->authorName !== null && $message->authorName !== ''
                ? $message->authorName . ': ' . $message->text
                : $message->text;
            $last = array_key_last($out);
            if ($last !== null && $out[$last]['role'] === $role) {
                $out[$last]['content'] .= "\n\n" . $content;
            } else {
                $out[] = ['role' => $role, 'content' => $content];
            }
        }
        while ($out !== [] && $out[0]['role'] !== 'user') {
            array_shift($out);
        }
        $last = array_key_last($out);
        if ($last !== null && $out[$last]['role'] === 'user') {
            $out[$last]['content'] .= sprintf("\n\n[The user is looking at saved revision %d of this quote.]", $revision);
        }

        return $out;
    }

    private static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
