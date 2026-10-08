<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Service;

use Closure;
use TriCab\Core\Finance\Ar\Contract\ArOutboundStore;
use TriCab\Core\Finance\Ar\Contract\ArRecordStore;
use TriCab\Core\Finance\Ar\Domain\ArActor;
use TriCab\Core\Finance\Ar\Domain\ArForbidden;
use TriCab\Core\Finance\Ar\Domain\ArInvalid;
use TriCab\Core\Finance\Ar\Domain\ArNotFound;
use TriCab\Core\Finance\Ar\Domain\ArOutboundMessage;
use TriCab\Core\Finance\Ar\Domain\ArRecord;
use TriCab\Core\Finance\Ar\Domain\ArUnavailable;
use TriCab\Core\Finance\Ar\Domain\ArUpstream;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Access\FinanceAccessScopeReader;
use TriCab\Core\Finance\Shared\Mail\FinanceMailDelivery;
use TriCab\Core\Finance\Shared\Mail\FinanceMailException;
use TriCab\Core\Finance\Shared\Mail\FinanceMailInvalid;
use TriCab\Core\Finance\Shared\Mail\FinanceMailLedger;
use TriCab\Core\Finance\Shared\Mail\FinanceMailMessage;
use TriCab\Core\Finance\Shared\Mail\FinanceMailRefused;
use TriCab\Core\Finance\Shared\Mail\FinanceMailRules;
use TriCab\Core\Finance\Shared\Mail\FinanceMailSender;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransportFailed;
use TriCab\Core\Finance\Shared\Mail\FinanceMailboxRegistry;

/**
 * The reply composer (T5.9): the mailboxes a person may send from, what has gone to a customer about a record, and
 * sending a reply (source routes 20 to 22, with `text_to_html`, `_address_list` and `_decode_attachments`).
 *
 * Every send goes through the shared finance mail service, so the grant, the limits, the ledger and the idempotency key
 * apply; a failed or suppressed send is reported, never shown as sent (question Q13). A reply is threaded into the
 * customer's conversation only when the original can be used (decision D56 for what happens when it cannot).
 */
final class ArMailService
{
    public const RECORD_NOT_FOUND = 'Record not found';
    public const MAIL_OFF = 'Mail is switched off';
    public const SENDING_OFF = 'Sending is switched off';

    /** @var Closure(): string */
    private readonly Closure $newId;

    /**
     * @param (callable(): string)|null $newId new 32-hex ids
     */
    public function __construct(
        private readonly ArRecordStore $records,
        private readonly ArOutboundStore $outbound,
        private readonly FinanceMailSender $mail,
        private readonly FinanceMailLedger $ledger,
        private readonly FinanceMailboxRegistry $mailboxes,
        private readonly FinanceAccessScopeReader $scopes,
        private readonly ArTime $time,
        private readonly string $defaultMailbox,
        private readonly bool $sendingEnabled,
        ?callable $newId = null,
    ) {
        $this->newId = Closure::fromCallable($newId ?? static fn (): string => bin2hex(random_bytes(16)));
    }

    /** The configured mailbox, lower-case. */
    public function defaultMailbox(): string
    {
        return strtolower(trim($this->defaultMailbox));
    }

    /**
     * The mailboxes Tri-AR may send from for this person: active, with a send grant for `AR`, and allowed by their
     * mailbox scope (no scope rows means all). The configured mailbox first, then by address.
     *
     * @return array{mailboxes: list<array{email: string, label: string}>, default: string}
     */
    public function sendMailboxes(ArActor $actor): array
    {
        $scope = $this->scopes->forUser($actor->userId, Module::APP_AR);
        $default = $this->defaultMailbox();
        $granted = [];
        foreach ($this->mailboxes->all() as $mailbox) {
            if (!$mailbox->canSend(Module::APP_AR) || !$scope->allowsMailbox($mailbox->address)) {
                continue;
            }
            $email = strtolower(trim($mailbox->address));
            $granted[] = ['email' => $email, 'label' => $mailbox->displayName !== '' ? $mailbox->displayName : $email];
        }
        usort($granted, static fn (array $a, array $b): int => [$a['email'] !== $default, $a['email']] <=> [$b['email'] !== $default, $b['email']]);

        return ['mailboxes' => $granted, 'default' => $default];
    }

    /**
     * What has been sent about a record, newest first, in the source's row shape. The subject, recipients, status and
     * error come from the ledger; the body is the link row's.
     *
     * @return list<array<string, mixed>>
     */
    public function history(string $recordId): array
    {
        if (!$this->records->exists($recordId)) {
            throw new ArNotFound(self::RECORD_NOT_FOUND);
        }
        $out = [];
        foreach ($this->outbound->outboundOf($recordId) as $link) {
            $delivery = $this->ledger->findById($link->deliveryId);
            if ($delivery !== null) {
                $out[] = self::present($link, $delivery);
            }
        }

        return $out;
    }

    /**
     * `POST /records/{id}/send-response`.
     *
     * @param array<string, mixed> $data `{to, cc, subject, response_body, from, attachments}`
     * @return array<string, mixed> `{success, record_id, threaded, message}`
     */
    public function send(ArActor $actor, string $recordId, array $data): array
    {
        if (!$this->sendingEnabled) {
            throw new ArUnavailable(self::SENDING_OFF);
        }
        $record = $this->records->find($recordId) ?? throw new ArNotFound(self::RECORD_NOT_FOUND);

        // The source's `_address_list(data.get("to") or record["sender_email"])`: an empty `to` means the sender.
        $to = ArMailText::addressList($data['to'] ?? null);
        if ($to === []) {
            $to = ArMailText::addressList($record->senderEmail);
        }
        $cc = ArMailText::addressList($data['cc'] ?? null);
        $subject = trim(self::scalar($data['subject'] ?? null));
        $subject = $subject !== '' ? $subject : 'Re: ' . $record->subject;
        $draft = self::scalar($data['response_body'] ?? null);
        $body = ArMailText::stripSubjectLine($draft !== '' ? $draft : $record->proposedResponse);
        if ($to === [] || $body === '') {
            throw new ArInvalid('Recipient and response body are required');
        }

        $from = strtolower(trim(self::scalar($data['from'] ?? null)));
        $from = $from !== '' ? $from : $this->defaultMailbox();
        // Checked against the registry and the person's scope even though the composer only offers granted
        // addresses: the request is what actually reaches Graph.
        if (!in_array($from, array_column($this->sendMailboxes($actor)['mailboxes'], 'email'), true)) {
            throw new ArForbidden('Tri-AR is not allowed to send from ' . $from . '. Ask a finance administrator to grant it that mailbox.');
        }
        $attachments = ArMailText::attachments($data['attachments'] ?? null);
        $bad = array_values(array_filter([...$to, ...$cc], static fn (string $a): bool => !ArMailText::isAddress($a)));
        if ($bad !== []) {
            throw new ArInvalid('Invalid email address: ' . implode(', ', $bad));
        }

        $linkId = ($this->newId)();
        // Thread only when the reply goes to the person who wrote in, from the mailbox the message was read from
        // (a Graph id belongs to its mailbox), and the original is still reachable (question Q1).
        $recordMailbox = strtolower(trim($record->mailbox !== '' ? $record->mailbox : $this->defaultMailbox()));
        $threadable = $record->sourceMessageId !== null && $record->sourceMessageId !== '' && !$record->messageUnreachable
            && array_map('strtolower', $to) === [strtolower(trim($record->senderEmail))]
            && $from === $recordMailbox;

        try {
            $result = $this->mail->send(new FinanceMailMessage(
                Module::APP_AR,
                $from,
                $to,
                $subject,
                ArMailText::toHtml($body),
                $cc,
                [],
                FinanceMailRules::CONTENT_HTML,
                $attachments,
                'ar-reply:' . $linkId,
                $actor->userId,
                $threadable ? $record->sourceMessageId : null,
            ));
        } catch (FinanceMailTransportFailed $failure) {
            if ($failure->delivery !== null) {
                $this->outbound->addOutbound($this->link($linkId, $record, $actor, $failure->delivery, false, $body));
            }
            throw new ArUpstream('Failed to send email: ' . $failure->getMessage(), ArUpstream::SEND, $failure);
        } catch (FinanceMailRefused $refused) {
            throw new ArForbidden($refused->getMessage(), [], $refused);
        } catch (FinanceMailInvalid $invalid) {
            throw new ArInvalid($invalid->getMessage());
        } catch (FinanceMailException $other) {
            throw new ArUpstream('Failed to send email: ' . $other->getMessage(), ArUpstream::SEND, $other);
        }

        $link = $this->link($linkId, $record, $actor, $result->delivery, $result->threaded, $body);
        if ($result->delivery->isSuppressed()) {
            // Mail is off: the ledger says so, the history shows it, and the record is left as it was.
            $this->outbound->addOutbound($link);
            throw new ArUpstream('Failed to send email: ' . self::MAIL_OFF, ArUpstream::SEND);
        }

        $sentAt = $result->delivery->sentAt ?? $this->time->now();
        $how = $result->threaded ? 'Replied in thread to' : 'Sent email reply to';
        $ccNote = $cc !== [] ? ' (cc ' . implode(', ', $cc) . ')' : '';
        $outcome = $how . ' ' . implode(', ', $to) . $ccNote . ' on ' . $sentAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i') . ' UTC';
        $this->records->transactional(function () use ($link, $record, $outcome): void {
            $this->outbound->addOutbound($link);
            $this->records->update($record->with(status: 'sent', followUpStatus: ArRecord::STATUS_COMPLETED, finalOutcome: $outcome, updatedAt: $this->time->now()));
        });

        return ['success' => true, 'record_id' => $recordId, 'threaded' => $result->threaded, 'message' => self::present($link, $result->delivery)];
    }

    private function link(string $id, ArRecord $record, ArActor $actor, FinanceMailDelivery $delivery, bool $threaded, string $body): ArOutboundMessage
    {
        return new ArOutboundMessage($id, $record->id, $delivery->id, $actor->userId, $actor->email, $threaded, $body, $this->time->now());
    }

    /**
     * A link and its ledger row as the source's outbound row. `pending` reads `sending`; `suppressed` reads `failed`
     * with the reason (decision D55).
     *
     * @return array<string, mixed>
     */
    private static function present(ArOutboundMessage $link, FinanceMailDelivery $delivery): array
    {
        $status = match ($delivery->status) {
            FinanceMailRules::STATUS_PENDING => 'sending',
            FinanceMailRules::STATUS_SUPPRESSED => 'failed',
            default => $delivery->status,
        };
        $error = $delivery->isSuppressed() ? self::MAIL_OFF : ($delivery->errorMessage ?? '');

        return [
            'id' => $link->id,
            'email_record_id' => $link->recordId,
            'sender_user' => $link->senderUser,
            'from_mailbox' => $delivery->mailbox,
            'to_addresses' => $delivery->to,
            'cc_addresses' => $delivery->cc,
            'subject' => $delivery->subject,
            'body' => $link->body,
            'threaded' => $link->threaded,
            'status' => $status,
            'error' => $error,
            'sent_at' => ArPresenter::iso($delivery->sentAt),
            'created_date' => ArPresenter::iso($link->createdAt),
            'updated_date' => ArPresenter::iso($delivery->sentAt ?? $link->createdAt),
        ];
    }

    private static function scalar(mixed $value): string
    {
        return is_scalar($value) && $value !== false ? (string) $value : '';
    }
}
