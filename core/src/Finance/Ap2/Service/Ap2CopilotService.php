<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Service;

use TriCab\Core\Finance\Ap2\Ai\Ap2CopilotProposal;
use TriCab\Core\Finance\Ap2\Contract\Ap2Copilot;
use TriCab\Core\Finance\Ap2\Contract\Ap2MailStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2SupplierStore;
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

    public function __construct(
        private readonly Ap2Copilot $copilot,
        private readonly Ap2MailStore $mail,
        private readonly Ap2SupplierStore $suppliers,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     * @return array{reply: string, proposals: list<array{type: string, label: string, payload: array<string, mixed>}>}
     */
    public function respond(array $body): array
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

        $reply = $this->copilot->respond($context, is_string($v['message'] ?? null) ? $v['message'] : '', $history);
        $proposals = [];
        foreach ($reply->proposals as $p) {
            if (in_array($p->type, Ap2CopilotProposal::TYPES, true)) {
                $proposals[] = ['type' => $p->type, 'label' => $p->label, 'payload' => $p->payload];
            }
        }

        return ['reply' => $reply->reply, 'proposals' => $proposals];
    }
}
