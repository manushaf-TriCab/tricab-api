<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Shared\Mail\FinanceMailbox;
use TriCab\Core\Finance\Shared\Mail\FinanceMailboxRegistry;

/**
 * GET /api/v1/finance/admin/mailboxes: the registry of shared mailboxes with what
 * each finance app may do with them. Read-only: in Phase 1 the registry is edited
 * by SQL. Gated on `FINANCE.ADMIN`.
 */
final class ListFinanceMailboxesAction
{
    public function __construct(
        private readonly FinanceMailboxRegistry $mailboxes,
        private readonly JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($request, $response);

        return $this->responder->success([
            'mailboxes' => array_map(static fn (FinanceMailbox $mailbox): array => [
                'id' => $mailbox->id,
                'address' => $mailbox->address,
                'displayName' => $mailbox->displayName,
                'purpose' => $mailbox->purpose,
                'active' => $mailbox->active,
                'grants' => array_map(
                    static fn (string $application, array $grant): array => [
                        'application' => $application,
                        'canRead' => $grant['read'],
                        'canSend' => $grant['send'],
                    ],
                    array_keys($mailbox->grants),
                    array_values($mailbox->grants),
                ),
            ], $this->mailboxes->all()),
        ]);
    }
}
