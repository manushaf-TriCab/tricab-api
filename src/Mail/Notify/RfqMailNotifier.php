<?php

declare(strict_types=1);

namespace TriCab\Api\Mail\Notify;

use Throwable;
use TriCab\Core\Mail\Application\DTO\CreatedDraft;
use TriCab\Core\Mail\Application\DTO\MailParty;
use TriCab\Core\Mail\Contract\RfqDraftNotifier;
use TriCab\Core\Shared\Time\Clock;

/**
 * In-app notices for a new email RFQ: one row per person who can work RFQs (everyone who can claim it), shown in
 * the platform's bell and linking to the draft in Quotegen. The mailbox's own user is skipped: it owns the draft
 * and nobody reads its bell. One person failing does not stop the rest.
 */
final class RfqMailNotifier implements RfqDraftNotifier
{
    public function __construct(
        private readonly RfqMailRecipients $recipients,
        private readonly RfqMailNotificationStore $store,
        private readonly Clock $clock,
        private readonly int $excludeUserId = 0,
    ) {
    }

    public function newEmailDraft(CreatedDraft $draft, MailParty $sender): void
    {
        $from = trim($sender->name) !== '' ? trim($sender->name) : $sender->address;
        $title = 'New email RFQ: ' . mb_substr($draft->title, 0, 200);
        $body = 'From ' . $from . '. Unassigned in Quotegen; claim it to work it.';
        $link = '/admin/quotegen/' . rawurlencode($draft->publicId);
        $now = $this->clock->now();
        foreach ($this->recipients->userIds() as $userId) {
            if ($userId === $this->excludeUserId) {
                continue;
            }
            try {
                $this->store->add(new RfqMailNotification(bin2hex(random_bytes(16)), $userId, $draft->publicId, $title, $body, $link, $now, false));
            } catch (Throwable $failure) {
                error_log('RFQ mail notification for user ' . $userId . ' not stored: ' . $failure->getMessage());
            }
        }
    }
}
