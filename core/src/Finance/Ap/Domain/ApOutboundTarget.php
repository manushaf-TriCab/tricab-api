<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Domain;

/**
 * What a piece of outgoing mail is recorded against in `invoice_outbound_messages`: exactly one of an invoice (mail to a vendor),
 * a scanned email (a reply) or an approval batch (the request to the Approvers and the outcome to the Preparer). The history of
 * an invoice or an email lists only its own; approval mail is in neither (source `store.py`).
 */
final readonly class ApOutboundTarget
{
    public const INVOICE = 'invoice';
    public const EMAIL = 'email';
    public const APPROVAL_BATCH = 'approval_batch';

    private function __construct(public string $kind, public string $id)
    {
    }

    public static function invoice(string $id): self
    {
        return new self(self::INVOICE, $id);
    }

    public static function email(string $id): self
    {
        return new self(self::EMAIL, $id);
    }

    public static function approvalBatch(string $id): self
    {
        return new self(self::APPROVAL_BATCH, $id);
    }

    /** The `entity_type` an audit event about this mail carries: the same words as {@see ApAuditEvent}'s. */
    public function entityType(): string
    {
        return $this->kind;
    }
}
