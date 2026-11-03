<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Mail;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Mail\Domain\Exception\MailNotFound;
use TriCab\Core\Mail\Infrastructure\MailboxBodyFetcher;
use TriCab\Api\Shared\Mailbox\InMemoryMailboxReader;
use TriCab\Core\Shared\Mailbox\MailboxAttachment;
use TriCab\Core\Shared\Mailbox\MailboxAttachmentSummary;
use TriCab\Core\Shared\Mailbox\MailboxMessage;
use TriCab\Core\Shared\Mailbox\MailboxMessageSummary;
use TriCab\Core\Shared\Mailbox\MailboxRecipient;

/** The RFQ mail envelope's source: the body as Graph holds it, the recipients, and every attachment listed (inline too). */
final class MailboxBodyFetcherTest extends TestCase
{
    public function test_the_envelope_source_is_the_untouched_body_both_recipient_lists_and_every_attachment(): void
    {
        $reader = new InMemoryMailboxReader();
        $reader->add(
            'nzprojects@tricab.com',
            new MailboxMessage(
                new MailboxMessageSummary('graph-1', 'FW: RFQ', 'jo@customer.example', 'Jo', MailFixtures::at(), true, 'Hi'),
                MailboxMessage::BODY_HTML,
                '<table><tr><td>4C 16mm2</td><td>120m</td></tr></table>',
                [new MailboxRecipient('nzprojects@tricab.com', 'NZ Projects')],
                [new MailboxRecipient('sam@tricab.com')],
            ),
            [
                new MailboxAttachment(new MailboxAttachmentSummary('a1', 'schedule.xlsx', 'application/octet-stream', 10), 'x'),
                new MailboxAttachment(new MailboxAttachmentSummary('a2', 'image001.png', 'image/png', 90_000, true), 'y'),
            ],
        );

        $source = (new MailboxBodyFetcher($reader))->envelopeSource('nzprojects@tricab.com', 'graph-1');

        self::assertSame('html', $source['type']);
        self::assertSame('<table><tr><td>4C 16mm2</td><td>120m</td></tr></table>', $source['content']);
        self::assertSame(['nzprojects@tricab.com'], array_map(static fn (MailboxRecipient $r): string => $r->address, $source['to']));
        self::assertSame(['sam@tricab.com'], array_map(static fn (MailboxRecipient $r): string => $r->address, $source['cc']));
        self::assertSame(['schedule.xlsx' => false, 'image001.png' => true], array_column(array_map(static fn (MailboxAttachmentSummary $a): array => [$a->name, $a->isInline], $source['attachments']), 1, 0));
    }

    public function test_a_message_gone_from_the_mailbox_is_not_found(): void
    {
        $this->expectException(MailNotFound::class);
        (new MailboxBodyFetcher(new InMemoryMailboxReader()))->envelopeSource('nzprojects@tricab.com', 'missing');
    }
}
