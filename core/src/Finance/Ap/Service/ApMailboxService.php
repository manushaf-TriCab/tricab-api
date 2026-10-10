<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Service;

use TriCab\Core\Finance\Ap\Domain\ApActor;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Mail\FinanceMailbox;
use TriCab\Core\Finance\Shared\Mail\FinanceMailboxRegistry;

/**
 * `GET /mailboxes` (source route 29): the mailboxes the registry grants Tri-AP (`finance.mailbox_grants`,
 * application key `AP`), limited to the person's mailbox scope. Read only (question Q14): the registry
 * is changed in TriCab Finance, so there is no create, update or delete.
 */
final class ApMailboxService
{
    public function __construct(private readonly FinanceMailboxRegistry $registry)
    {
    }

    /**
     * @return list<array{id: string, email: string, label: string, is_active: bool, can_read: bool, can_send: bool}>
     */
    public function list(ApActor $actor): array
    {
        $rows = [];
        foreach ($this->registry->all() as $mailbox) {
            if (!isset($mailbox->grants[Module::APP_AP]) || !$actor->mailboxScope->allowsMailbox($mailbox->address)) {
                continue;
            }
            $rows[] = self::row($mailbox);
        }

        return $rows;
    }

    /** @return array{id: string, email: string, label: string, is_active: bool, can_read: bool, can_send: bool} */
    private static function row(FinanceMailbox $mailbox): array
    {
        return [
            'id' => $mailbox->id,
            'email' => $mailbox->address,
            'label' => $mailbox->displayName,
            'is_active' => $mailbox->active,
            'can_read' => $mailbox->canRead(Module::APP_AP),
            'can_send' => $mailbox->canSend(Module::APP_AP),
        ];
    }
}
