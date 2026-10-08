<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Service;

use TriCab\Core\Finance\Ar\Contract\ArRecordStore;
use TriCab\Core\Finance\Ar\Domain\ArActor;
use TriCab\Core\Finance\Ar\Domain\ArForbidden;
use TriCab\Core\Finance\Ar\Domain\ArNotFound;
use TriCab\Core\Finance\Ar\Domain\ArUpstream;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Mail\FinanceMailException;
use TriCab\Core\Finance\Shared\Mail\FinanceMailRefused;
use TriCab\Core\Finance\Shared\Mailbox\FinanceMailboxReader;
use TriCab\Core\Shared\Mailbox\MailboxItemNotFound;
use TriCab\Core\Shared\Mailbox\MailboxMessage;
use TriCab\Core\Shared\Mailbox\MailboxReadFailed;
use TriCab\Core\Shared\Mailbox\MailboxRecipient;

/**
 * `GET /records/{id}/body` (source route 8): the original message, formatted, read from the mailbox for the app's
 * viewer. The scan stores the body as plain text for search and the model, which loses the tables remittances
 * are laid out in.
 *
 * The content is **untrusted**: anyone can mail the shared address. It is returned unmodified and the client
 * renders it inertly (a sandboxed frame, decision D47). The read goes through the finance mailbox rules (the app's
 * read grant, the person's scope, an audit row), so a missing grant is a 403 and a Graph failure a typed 502.
 */
final class ArOriginalMessageService
{
    public const NOT_FROM_MAILBOX = 'This record did not come from the mailbox';
    public const UNREACHABLE = 'The original message is no longer reachable';
    public const GONE = 'The original message is no longer in the mailbox';

    public function __construct(
        private readonly ArRecordStore $records,
        private readonly FinanceMailboxReader $mailbox,
        private readonly string $defaultMailbox,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function original(ArActor $actor, string $recordId): array
    {
        $record = $this->records->find($recordId) ?? throw new ArNotFound(ArRecordService::NOT_FOUND);
        if ($record->sourceMessageId === null || $record->sourceMessageId === '') {
            throw new ArNotFound($record->messageUnreachable ? self::UNREACHABLE : self::NOT_FROM_MAILBOX);
        }
        if ($record->messageUnreachable) {
            throw new ArNotFound(self::UNREACHABLE);
        }

        try {
            $message = $this->mailbox->getMessage(
                Module::APP_AR,
                $record->mailbox !== '' ? $record->mailbox : $this->defaultMailbox,
                $record->sourceMessageId,
                $actor->userId,
            );
        } catch (FinanceMailRefused $refused) {
            throw new ArForbidden($refused->getMessage(), [], $refused);
        } catch (MailboxItemNotFound $gone) {
            throw new ArNotFound(self::GONE);
        } catch (MailboxReadFailed | FinanceMailException $failure) {
            throw new ArUpstream('Could not fetch the message from Outlook: ' . $failure->getMessage(), ArUpstream::MAILBOX, $failure);
        }

        return self::shape($message, $record->subject, $record->body ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    private static function shape(MailboxMessage $message, string $storedSubject, string $storedBody): array
    {
        $summary = $message->summary;
        $people = static fn (array $list): array => array_map(
            static fn (MailboxRecipient $r): array => ['name' => $r->name, 'address' => $r->address],
            $list,
        );

        return [
            'subject' => $summary->subject !== '' ? $summary->subject : $storedSubject,
            'from' => ['name' => $summary->fromName, 'address' => $summary->fromAddress],
            'to' => $people($message->to),
            'cc' => $people($message->cc),
            'received_at' => ArPresenter::instant($summary->receivedAt),
            'content_type' => $message->bodyType,
            'content' => $message->body !== '' ? $message->body : $storedBody,
            'untrusted' => true,
        ];
    }
}
