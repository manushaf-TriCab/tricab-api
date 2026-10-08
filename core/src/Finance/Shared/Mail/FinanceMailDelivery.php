<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Mail;

use DateTimeImmutable;

/**
 * One row of the mail ledger (`finance.mail_deliveries`). Holds a hash of the
 * body and the attachment names, never the content, and no bcc address.
 */
final readonly class FinanceMailDelivery
{
    /**
     * @param list<string> $to
     * @param list<string> $cc
     * @param list<string> $attachmentNames
     */
    public function __construct(
        public string $id,
        public string $application,
        public ?int $actorUserId,
        public string $mailbox,
        public array $to,
        public array $cc,
        public string $subject,
        public string $bodySha256,
        public array $attachmentNames,
        public ?string $idempotencyKey,
        public string $status,
        public ?string $errorMessage,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $sentAt,
    ) {
    }

    public function isSent(): bool
    {
        return $this->status === FinanceMailRules::STATUS_SENT;
    }

    public function isSuppressed(): bool
    {
        return $this->status === FinanceMailRules::STATUS_SUPPRESSED;
    }

    public function withSent(DateTimeImmutable $at): self
    {
        return new self(
            $this->id, $this->application, $this->actorUserId, $this->mailbox, $this->to, $this->cc,
            $this->subject, $this->bodySha256, $this->attachmentNames, $this->idempotencyKey,
            FinanceMailRules::STATUS_SENT, null, $this->createdAt, $at,
        );
    }

    public function withFailed(string $error): self
    {
        return new self(
            $this->id, $this->application, $this->actorUserId, $this->mailbox, $this->to, $this->cc,
            $this->subject, $this->bodySha256, $this->attachmentNames, $this->idempotencyKey,
            FinanceMailRules::STATUS_FAILED, $error, $this->createdAt, null,
        );
    }

    public function withSuppressed(string $reason): self
    {
        return new self(
            $this->id, $this->application, $this->actorUserId, $this->mailbox, $this->to, $this->cc,
            $this->subject, $this->bodySha256, $this->attachmentNames, $this->idempotencyKey,
            FinanceMailRules::STATUS_SUPPRESSED, $reason, $this->createdAt, null,
        );
    }
}
