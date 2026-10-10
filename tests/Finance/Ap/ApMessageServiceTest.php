<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Finance\Ap\Domain\ApForbidden;
use TriCab\Core\Finance\Ap\Domain\ApNotFound;
use TriCab\Core\Finance\Ap\Domain\ApUpstream;
use TriCab\Core\Shared\Mailbox\MailboxMessage;

/**
 * The live reads of a scanned email (T6.6, Q18) through the shared finance reader and a fake mailbox: the body, the
 * attachment list and one attachment, the 404s, the typed 403 and 502, and the audit row each read leaves. Nothing reaches Graph.
 */
final class ApMessageServiceTest extends TestCase
{
    private ApEmailWorld $world;

    protected function setUp(): void
    {
        $this->world = new ApEmailWorld();
    }

    /** @return list<string> */
    private function auditActions(): array
    {
        return array_map(static fn ($event): string => $event->action, $this->world->mailAudit->events);
    }

    public function test_the_body_comes_back_in_the_pages_shape_unmodified_and_marked_untrusted(): void
    {
        $this->world->email('e1', graphMessageId: 'imm-1');
        $hostile = '<script>alert(1)</script><img src="http://evil.example/x"><table><tr><td>INV-1</td></tr></table>';
        $this->world->message('imm-1', $hostile);

        $out = $this->world->messages->body(ApInvoiceFixtures::actor(), 'e1');

        self::assertSame('Live subject', $out['subject']);
        self::assertSame(['name' => 'Vera Vendor', 'address' => 'billing@vendor.example.test'], $out['from']);
        self::assertSame([['name' => 'AP', 'address' => 'ap@example.test']], $out['to']);
        self::assertSame([['name' => '', 'address' => 'boss@example.test']], $out['cc']);
        self::assertSame('2026-10-05T01:00:00.000000+00:00', $out['received_at']);
        self::assertSame('html', $out['content_type']);
        self::assertSame($hostile, $out['content']);
        self::assertTrue($out['untrusted']);
        self::assertSame(['finance.mail.message_read'], $this->auditActions());
    }

    public function test_an_empty_body_and_subject_fall_back_to_the_stored_preview_as_text_and_subject(): void
    {
        $this->world->email('e1', graphMessageId: 'imm-1', subject: 'Stored subject', bodyPreview: 'Stored preview');
        $this->world->message('imm-1', '', MailboxMessage::BODY_HTML, [], '');

        $out = $this->world->messages->body(ApInvoiceFixtures::actor(), 'e1');

        self::assertSame('Stored subject', $out['subject']);
        self::assertSame('Stored preview', $out['content']);
        self::assertSame('text', $out['content_type']);
    }

    public function test_a_plain_text_body_says_text_and_a_binned_email_is_still_readable(): void
    {
        $this->world->email('e1', graphMessageId: 'imm-1', deletedAt: ApEmailWorld::utc('2026-10-06 00:00:00'));
        $this->world->message('imm-1', 'Hello', MailboxMessage::BODY_TEXT);

        $out = $this->world->messages->body(ApInvoiceFixtures::actor(), 'e1');

        self::assertSame(['text', 'Hello'], [$out['content_type'], $out['content']]);
    }

    public function test_an_unknown_or_out_of_scope_email_is_a_404_and_the_mailbox_is_not_asked(): void
    {
        $this->world->email('e1', graphMessageId: 'imm-1');
        $this->world->message('imm-1');

        foreach ([[ApInvoiceFixtures::actor(), 'nope'], [ApInvoiceFixtures::actor(['other@example.test']), 'e1']] as [$actor, $id]) {
            foreach (['body', 'attachments'] as $call) {
                try {
                    $this->world->messages->{$call}($actor, $id);
                    self::fail('Expected a 404.');
                } catch (ApNotFound $notFound) {
                    self::assertSame('Not found', $notFound->getMessage());
                }
            }
            try {
                $this->world->messages->file($actor, $id, 'a1');
                self::fail('Expected a 404.');
            } catch (ApNotFound $notFound) {
                self::assertSame('Not found', $notFound->getMessage());
            }
        }
        self::assertSame(0, $this->world->mailbox->reads);
        self::assertSame([], $this->world->mailAudit->events);
    }

    public function test_a_message_gone_from_the_mailbox_and_a_graph_failure_are_502s_with_the_sources_prefix(): void
    {
        $this->world->email('gone', graphMessageId: 'missing');
        $this->world->email('flaky', graphMessageId: 'imm-2', receivedAt: ApEmailWorld::utc('2026-10-04 01:00:00'));
        $this->world->message('imm-2');
        $this->world->reader->failMessages = ['imm-2'];

        foreach (['gone', 'flaky'] as $id) {
            try {
                $this->world->messages->body(ApInvoiceFixtures::actor(), $id);
                self::fail('Expected a 502.');
            } catch (ApUpstream $upstream) {
                self::assertStringStartsWith('Could not fetch the message from Outlook: ', $upstream->getMessage());
                self::assertSame(502, $upstream->httpStatus());
                self::assertSame('FINANCE_AP_UPSTREAM_FAILED', $upstream->errorCode());
            }
        }
        self::assertSame([], $this->world->mailAudit->events, 'a failed read leaves no audit row');
    }

    public function test_a_mailbox_without_a_read_grant_is_a_typed_403(): void
    {
        $this->world->email('e1', mailbox: 'closed@example.test');

        $this->expectException(ApForbidden::class);
        $this->world->messages->body(ApInvoiceFixtures::actor(), 'e1');
    }

    public function test_the_persons_scope_is_also_checked_by_the_reader(): void
    {
        $this->world->scopes->grant(7, 'AP', \TriCab\Core\Finance\Shared\Access\FinanceAccessScope::TYPE_MAILBOX, 'someone-else@example.test');
        $this->world->email('e1', graphMessageId: 'imm-1');
        $this->world->message('imm-1');

        // The use case's scope is the actor's; the reader asks the access-scope reader for the same person.
        $this->expectException(ApForbidden::class);
        $this->world->messages->body(ApInvoiceFixtures::actor(), 'e1');
    }

    public function test_the_attachment_list_omits_inline_images_but_keeps_inline_pdfs_and_uses_the_defaults(): void
    {
        $this->world->email('e1', graphMessageId: 'imm-1');
        $this->world->message('imm-1', attachments: [
            ApEmailWorld::file('a1', 'bill.pdf', 'application/pdf', '%PDF-1'),
            ApEmailWorld::file('a2', 'logo.png', 'image/png', 'png', true),
            ApEmailWorld::file('a3', 'inline.pdf', 'Application/PDF', '%PDF-2', true),
            ApEmailWorld::file('a4', '', '', 'x'),
        ]);

        $rows = $this->world->messages->attachments(ApInvoiceFixtures::actor(), 'e1');

        self::assertSame([
            ['id' => 'a1', 'name' => 'bill.pdf', 'content_type' => 'application/pdf', 'size' => 6],
            ['id' => 'a3', 'name' => 'inline.pdf', 'content_type' => 'Application/PDF', 'size' => 6],
            ['id' => 'a4', 'name' => 'attachment', 'content_type' => 'application/octet-stream', 'size' => 1],
        ], $rows);
        self::assertSame(['finance.mail.attachments_listed'], $this->auditActions());
    }

    public function test_the_attachment_list_failing_is_a_502(): void
    {
        $this->world->email('e1', graphMessageId: 'imm-1');
        $this->world->message('imm-1');
        $this->world->reader->failAttachmentListing = true;

        $this->expectException(ApUpstream::class);
        $this->expectExceptionMessage('Could not fetch the attachments from Outlook: Graph could not list attachments');
        $this->world->messages->attachments(ApInvoiceFixtures::actor(), 'e1');
    }

    public function test_an_attachment_file_has_its_type_cleaned_its_name_made_safe_and_one_audit_row(): void
    {
        $this->world->email('e1', graphMessageId: 'imm-1');
        $this->world->message('imm-1', attachments: [
            ApEmailWorld::file('a1', '..\\..\\evil "name".pdf', 'Application/PDF; name="x"', '%PDF-1.7 body'),
            ApEmailWorld::file('a2', 'page.html', 'text/html', '<script>1</script>'),
            ApEmailWorld::file('a3', '', '', 'bytes'),
            ApEmailWorld::file('a4', 'empty.txt', 'text/plain', ''),
        ]);
        $actor = ApInvoiceFixtures::actor();

        $pdf = $this->world->messages->file($actor, 'e1', 'a1');
        $html = $this->world->messages->file($actor, 'e1', 'a2');
        $bare = $this->world->messages->file($actor, 'e1', 'a3');

        self::assertSame(['%PDF-1.7 body', 'application/pdf', 'evil _name_.pdf', true], [$pdf->bytes, $pdf->contentType, $pdf->downloadName, $pdf->inlineAllowed]);
        self::assertSame(['text/html', false], [$html->contentType, $html->inlineAllowed], 'HTML is never shown in place');
        self::assertSame(['application/octet-stream', 'attachment', false], [$bare->contentType, $bare->downloadName, $bare->inlineAllowed]);
        self::assertSame(['finance.mail.attachment_read', 'finance.mail.attachment_read', 'finance.mail.attachment_read'], $this->auditActions());
        try {
            $this->world->messages->file($actor, 'e1', 'a4');
            self::fail('Expected a 404.');
        } catch (ApNotFound $notFound) {
            self::assertSame('This attachment has no file content', $notFound->getMessage());
        }
    }

    public function test_every_inline_type_is_allowed_and_nothing_else(): void
    {
        $this->world->email('e1', graphMessageId: 'imm-1');
        $types = ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'text/plain', 'image/svg+xml', 'text/html', 'application/zip'];
        $files = [];
        foreach ($types as $n => $type) {
            $files[] = ApEmailWorld::file('f' . $n, 'f' . $n, $type, 'x');
        }
        $this->world->message('imm-1', attachments: $files);

        $allowed = [];
        foreach ($types as $n => $type) {
            if ($this->world->messages->file(ApInvoiceFixtures::actor(), 'e1', 'f' . $n)->inlineAllowed) {
                $allowed[] = $type;
            }
        }

        self::assertSame(['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'text/plain'], $allowed);
    }

    public function test_an_unknown_attachment_and_a_graph_failure_are_502s(): void
    {
        $this->world->email('e1', graphMessageId: 'imm-1');
        $this->world->message('imm-1', attachments: [ApEmailWorld::file('a1', 'a.pdf', 'application/pdf', 'x')]);
        $this->world->reader->failAttachments = ['a1'];

        foreach (['a1', 'nope'] as $id) {
            try {
                $this->world->messages->file(ApInvoiceFixtures::actor(), 'e1', $id);
                self::fail('Expected a 502.');
            } catch (ApUpstream $upstream) {
                self::assertStringStartsWith('Could not fetch the attachment from Outlook: ', $upstream->getMessage());
            }
        }
    }
}
