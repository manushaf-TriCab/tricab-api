<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Mail\Intake;

use DateTimeImmutable;
use TriCab\Core\Mail\Application\DTO\MailParty;
use TriCab\Core\Mail\Intake\IncomingMail;
use TriCab\Core\Shared\Mailbox\MailboxMessage;
use TriCab\Core\Shared\Mailbox\MailboxMessageSummary;

final class Mails
{
    public const MAILBOX = 'melbourne@tricab.com';

    /**
     * @param array<string, string>|null $headers
     */
    public static function mail(
        string $key,
        string $subject = 'Quote for 100m of 3C 2.5mm',
        string $from = 'jo@customer.example',
        ?array $headers = [],
        bool $hasAttachments = false,
        string $conversation = '',
        string $type = '#microsoft.graph.message',
    ): IncomingMail {
        return new IncomingMail(
            'graph-' . $key,
            '<' . $key . '@customer.example>',
            $conversation === '' ? 'conv-' . $key : $conversation,
            $subject,
            new MailParty('Jo Customer', $from),
            [new MailParty('TriCab Melbourne', self::MAILBOX)],
            [],
            new DateTimeImmutable('2026-10-18 09:00:00 UTC'),
            'Hi, could you quote us',
            false,
            $hasAttachments,
            $type,
            $headers,
        );
    }

    public static function body(string $key, string $html = '<p>Hi,</p><p>Please quote 100m of 3C 2.5mm.</p>', bool $hasAttachments = false): MailboxMessage
    {
        return new MailboxMessage(
            new MailboxMessageSummary('graph-' . $key, 'subject', 'jo@customer.example', 'Jo', new DateTimeImmutable('2026-10-18 09:00:00 UTC'), $hasAttachments, 'preview'),
            MailboxMessage::BODY_HTML,
            $html,
        );
    }
}
