<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Service;

use Throwable;
use TriCab\Core\Finance\Ap2\Ai\Ap2CopilotProposal;
use TriCab\Core\Finance\Ap2\Contract\Ap2Copilot;
use TriCab\Core\Finance\Ap2\Contract\Ap2CopilotHistoryStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2MailStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2SupplierStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2Actor;
use TriCab\Core\Finance\Ap2\Domain\Ap2Failure;
use TriCab\Core\Finance\Ap2\Domain\Ap2Invalid;

/**
 * `POST /copilot`: `{mailId?, supplierId?, message, history?}` → `{reply, proposals}`. It checks the ids and the
 * message, hands them to {@see Ap2Copilot}, and passes on only proposals of a known type. Nothing it proposes is
 * applied here; the web applies each through the normal route, under the person's own permissions.
 */
final class Ap2CopilotService
{
    public const MAX_HISTORY = 20;

    /** The most messages kept (and shown) per conversation. */
    public const MAX_STORED = 30;

    public function __construct(
        private readonly Ap2Copilot $copilot,
        private readonly Ap2MailStore $mail,
        private readonly Ap2SupplierStore $suppliers,
        private readonly Ap2CopilotHistoryStore $conversations,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     * @return array{reply: string, proposals: list<array{type: string, label: string, payload: array<string, mixed>}>}
     */
    public function respond(Ap2Actor $actor, array $body): array
    {
        $input = new Ap2Input($body);
        $input->id('mailId');
        $input->id('supplierId');
        $input->text('message', 4000, true);
        $issues = $input->issues();
        if (!$input->has('message')) {
            $issues[] = 'message is required';
        }
        $v = $input->values();
        $context = [];
        if (is_string($v['mailId'] ?? null)) {
            if ($this->mail->findMail($v['mailId']) === null) {
                $issues[] = 'mailId does not match an email';
            }
            $context['mailId'] = $v['mailId'];
        }
        if (is_string($v['supplierId'] ?? null)) {
            if ($this->suppliers->find($v['supplierId']) === null) {
                $issues[] = 'supplierId does not match a supplier';
            }
            $context['supplierId'] = $v['supplierId'];
        }
        $history = [];
        $rawHistory = $body['history'] ?? [];
        if (!is_array($rawHistory) || !array_is_list($rawHistory)) {
            $issues[] = 'history must be a list';
        } else {
            foreach (array_slice($rawHistory, -self::MAX_HISTORY) as $turn) {
                $role = is_array($turn) ? ($turn['role'] ?? null) : null;
                $content = is_array($turn) ? ($turn['content'] ?? null) : null;
                if (!in_array($role, ['user', 'assistant'], true) || !is_string($content)) {
                    $issues[] = 'history items must be {role: user|assistant, content: text}';
                    break;
                }
                $history[] = ['role' => (string) $role, 'content' => mb_substr($content, 0, 4000)];
            }
        }
        if ($issues !== []) {
            throw new Ap2Invalid('The question is invalid', Ap2Failure::details(array_values(array_unique($issues))));
        }

        $mailId = is_string($v['mailId'] ?? null) ? $v['mailId'] : null;
        $supplierId = is_string($v['supplierId'] ?? null) ? $v['supplierId'] : null;
        if ($history === []) {
            $history = $this->stored($actor, $mailId, $supplierId);
        }

        $reply = $this->copilot->respond($context, is_string($v['message'] ?? null) ? $v['message'] : '', $history);
        $proposals = [];
        foreach ($reply->proposals as $p) {
            if (in_array($p->type, Ap2CopilotProposal::TYPES, true)) {
                $proposals[] = ['type' => $p->type, 'label' => $p->label, 'payload' => $p->payload];
            }
        }

        $this->remember($actor, $mailId, $supplierId, is_string($v['message'] ?? null) ? $v['message'] : '', $reply->reply, $proposals);

        return ['reply' => $reply->reply, 'proposals' => $proposals];
    }

    /**
     * `GET /copilot/history?mailId=…|supplierId=…`: the calling person's stored messages for one conversation, oldest first.
     *
     * @param array<string, mixed> $query
     * @return array{messages: list<array{id: string, role: string, content: string, proposals: list<array<string, mixed>>, createdAt: string}>}
     */
    public function history(Ap2Actor $actor, array $query): array
    {
        $input = new Ap2Input($query);
        $input->id('mailId');
        $input->id('supplierId');
        $issues = $input->issues();
        $v = $input->values();
        $mailId = is_string($v['mailId'] ?? null) ? $v['mailId'] : null;
        $supplierId = is_string($v['supplierId'] ?? null) ? $v['supplierId'] : null;
        if ($issues === [] && ($mailId === null) === ($supplierId === null)) {
            $issues[] = 'exactly one of mailId and supplierId is required';
        }
        if ($mailId !== null && $this->mail->findMail($mailId) === null) {
            $issues[] = 'mailId does not match an email';
        }
        if ($supplierId !== null && $this->suppliers->find($supplierId) === null) {
            $issues[] = 'supplierId does not match a supplier';
        }
        if ($issues !== []) {
            throw new Ap2Invalid('The history request is invalid', Ap2Failure::details(array_values(array_unique($issues))));
        }

        $messages = [];
        foreach ($this->conversations->recent($actor->userId, $mailId, $supplierId, self::MAX_STORED) as $m) {
            $messages[] = [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'proposals' => $m->proposals,
                'createdAt' => $m->createdAt->format(DATE_ATOM),
            ];
        }

        return ['messages' => $messages];
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function stored(Ap2Actor $actor, ?string $mailId, ?string $supplierId): array
    {
        if ($mailId === null && $supplierId === null) {
            return [];
        }
        try {
            $turns = [];
            foreach ($this->conversations->recent($actor->userId, $mailId, $supplierId, self::MAX_HISTORY) as $m) {
                $turns[] = ['role' => $m->role, 'content' => $m->content];
            }

            return $turns;
        } catch (Throwable $e) {
            error_log('Tri-AP2 Copilot history could not be read: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Stores the question and the answer; a failure here is logged and never costs the person their reply.
     *
     * @param list<array{type: string, label: string, payload: array<string, mixed>}> $proposals
     */
    private function remember(Ap2Actor $actor, ?string $mailId, ?string $supplierId, string $message, string $reply, array $proposals): void
    {
        if ($mailId === null && $supplierId === null) {
            return;
        }
        try {
            $this->conversations->append($actor->userId, $mailId, $supplierId, 'user', $message);
            $this->conversations->append($actor->userId, $mailId, $supplierId, 'assistant', $reply, $proposals);
            $this->conversations->prune($actor->userId, $mailId, $supplierId, self::MAX_STORED);
        } catch (Throwable $e) {
            error_log('Tri-AP2 Copilot history could not be stored: ' . $e->getMessage());
        }
    }
}
