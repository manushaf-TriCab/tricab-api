<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Service;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Core\Finance\Ap2\Domain\Ap2Approval;
use TriCab\Core\Finance\Ap2\Domain\Ap2ApprovalEvent;
use TriCab\Core\Finance\Ap2\Domain\Ap2Assessment;
use TriCab\Core\Finance\Ap2\Domain\Ap2Export;
use TriCab\Core\Finance\Ap2\Domain\Ap2Notification;
use TriCab\Core\Finance\Ap2\Domain\Ap2Payment;
use TriCab\Core\Finance\Ap2\Domain\Ap2Settings;
use TriCab\Core\Finance\Ap2\Domain\Ap2Statement;
use TriCab\Core\Finance\Ap2\Domain\Ap2HistoryEntry;
use TriCab\Core\Finance\Ap2\Domain\Ap2Invoice;
use TriCab\Core\Finance\Ap2\Domain\Ap2Mail;
use TriCab\Core\Finance\Ap2\Domain\Ap2MailAttachment;
use TriCab\Core\Finance\Ap2\Domain\Ap2Person;
use TriCab\Core\Finance\Ap2\Domain\Ap2Supplier;

/** The contract's JSON shapes: camelCase, money as numbers, times as ISO UTC, computed fields included. */
final class Ap2Presenter
{
    public static function time(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    /** @return array{userId: int, name: string, email: string}|null */
    public static function person(?Ap2Person $person): ?array
    {
        return $person?->toArray();
    }

    /**
     * @param array{balance: float, overdue: float, openInvoices: int} $position AUD
     * @return array<string, mixed>
     */
    public static function supplier(Ap2Supplier $s, array $position): array
    {
        return [
            'id' => $s->id,
            'name' => $s->name,
            'industry' => $s->industry,
            'email' => $s->email,
            'contact' => $s->contact,
            'cc' => $s->cc,
            'entity' => $s->entity,
            'phone' => $s->phone,
            'terms' => $s->terms,
            'owner' => self::person($s->owner),
            'bankVerified' => $s->bankVerified,
            'bankChange' => $s->bankChange,
            'hold' => $s->hold,
            'notes' => $s->notes,
            'odooPartnerId' => $s->odooPartnerId,
            'balance' => round($position['balance'], 2),
            'overdue' => round($position['overdue'], 2),
            'openInvoices' => $position['openInvoices'],
            'createdAt' => self::time($s->createdAt),
            'updatedAt' => self::time($s->updatedAt),
        ];
    }

    /** @return array<string, mixed> */
    public static function invoice(Ap2Invoice $b, ?Ap2Supplier $supplier, Ap2Assessment $a): array
    {
        return [
            'id' => $b->id,
            'supplierId' => $b->supplierId,
            'supplierName' => $supplier->name ?? '',
            'number' => $b->number,
            'entity' => $b->entity,
            'currency' => $b->currency,
            'net' => round($b->net, 2),
            'tax' => round($b->tax, 2),
            'total' => round($b->total, 2),
            'issued' => $b->issued,
            'due' => $b->due,
            'kind' => $b->kind,
            'po' => $b->po,
            'quantity' => $b->quantity,
            'orderedQty' => $b->orderedQty,
            'receivedQty' => $b->receivedQty,
            'poUnitPrice' => $b->poUnitPrice,
            'receiptRef' => $b->receiptRef,
            'serviceAccepted' => $b->serviceAccepted,
            'noPoReason' => $b->noPoReason,
            'gl' => $b->gl,
            'taxCode' => $b->taxCode,
            'costCentre' => $b->costCentre,
            'credit' => round($b->credit, 2),
            'creditRef' => $b->creditRef,
            'creditNet' => round($b->creditNet, 2),
            'creditTax' => round($b->creditTax, 2),
            'creditResolved' => $b->creditResolved,
            'paid' => round($b->paid, 2),
            'stage' => $b->stage,
            'verified' => $b->verified,
            'importState' => $b->importState,
            'odooRef' => $b->odooRef,
            'odooMoveId' => $b->odooMoveId,
            'approvalId' => $b->approvalId,
            'excluded' => $b->excluded,
            'excludedReason' => $b->excludedReason,
            'paymentHold' => $b->paymentHold,
            'sourceDoc' => $b->sourceDoc,
            'sourceFileId' => $b->sourceFileId,
            'confidence' => $b->confidence,
            'sourceFingerprint' => $b->sourceFingerprint,
            'mailId' => $b->mailId,
            'owner' => self::person($b->owner),
            'validation' => $a->validation,
            // The prototype's matching() answers one string; the contract types it string[], so it is a one-item list
            // ('Matched' or the reason). `matchStatus` is the same text for a page that wants a string.
            'matching' => [$a->matching],
            'matchStatus' => $a->matching,
            'duplicateOf' => $a->duplicateOf,
            'queue' => $a->queue,
            'approvalCurrent' => $a->approvalCurrent,
            'csvEligible' => $a->csvEligible,
            'exportIssues' => $a->exportIssues,
            'paymentIssues' => $a->paymentIssues,
            'balance' => round($a->balance, 2),
            'locked' => $a->locked,
            'daysOverdue' => $a->daysOverdue,
            'createdAt' => self::time($b->createdAt),
            'updatedAt' => self::time($b->updatedAt),
        ];
    }

    /**
     * @param list<Ap2ApprovalEvent> $events
     * @return array<string, mixed>
     */
    public static function approval(Ap2Approval $a, DateTimeImmutable $now, bool $snapshotCurrent, array $events): array
    {
        return [
            'id' => $a->id,
            'kind' => $a->kind,
            'retryOf' => $a->retryOf,
            'invoiceId' => $a->invoiceId,
            'mailId' => $a->mailId,
            'paymentId' => $a->paymentId,
            'supplierId' => $a->supplierId,
            'subject' => $a->subject,
            'summary' => $a->summary,
            'requester' => $a->requester->toArray(),
            'approver' => $a->approver->toArray(),
            'status' => $a->effectiveStatus($now),
            'snapshotCurrent' => $snapshotCurrent,
            'requestedAt' => self::time($a->requestedAt),
            'decidedAt' => $a->decidedAt === null ? null : self::time($a->decidedAt),
            'comment' => $a->comment,
            'expiresAt' => $a->expiresAt === null ? null : self::time($a->expiresAt),
            'history' => array_map(static fn (Ap2ApprovalEvent $e): array => [
                'action' => $e->action,
                'by' => $e->by->toArray(),
                'at' => self::time($e->at),
                'comment' => $e->comment,
            ], $events),
            'createdAt' => self::time($a->requestedAt),
            'updatedAt' => self::time($a->updatedAt),
        ];
    }

    /** @return array<string, mixed> */
    public static function payment(Ap2Payment $p, string $supplierName = '', string $invoiceNumber = ''): array
    {
        return [
            'id' => $p->id,
            'supplierId' => $p->supplierId,
            'supplierName' => $supplierName,
            'invoiceId' => $p->invoiceId,
            'invoiceNumber' => $invoiceNumber,
            'amount' => round($p->amount, 2),
            'currency' => $p->currency,
            'date' => $p->date,
            'status' => $p->status,
            'approvalId' => $p->approvalId,
            'odooPaymentRef' => $p->odooPaymentRef,
            'rejection' => $p->rejection,
            'createdAt' => self::time($p->createdAt),
            'updatedAt' => self::time($p->updatedAt),
        ];
    }

    /** @return array<string, mixed> */
    public static function notification(Ap2Notification $n): array
    {
        return ['id' => $n->id, 'title' => $n->title, 'body' => $n->body, 'at' => self::time($n->at), 'read' => $n->read, 'link' => $n->link];
    }

    /** @return array<string, mixed> */
    public static function export(Ap2Export $x, bool $withCsv = false): array
    {
        $out = [
            'id' => $x->id,
            'invoiceIds' => $x->invoiceIds,
            'headers' => $x->headers,
            'rows' => $x->rows,
            'createdAt' => self::time($x->createdAt),
            'createdBy' => $x->createdBy->toArray(),
        ];
        if ($withCsv) {
            $out['csv'] = $x->csv;
        }

        return $out;
    }

    /**
     * @param float $postedAp what the ledger says is open for this supplier in the statement's currency
     * @return array<string, mixed>
     */
    public static function statement(Ap2Statement $s, float $postedAp): array
    {
        return [
            'id' => $s->id,
            'supplierId' => $s->supplierId,
            'date' => $s->date,
            'amount' => round($s->amount, 2),
            'currency' => $s->currency,
            'reference' => $s->reference,
            'status' => $s->status,
            'evidence' => $s->evidence,
            'notes' => $s->notes,
            'reviewedBy' => self::person($s->reviewedBy),
            'reviewedAt' => $s->reviewedAt === null ? null : self::time($s->reviewedAt),
            'mailId' => $s->mailId,
            'postedAp' => round($postedAp, 2),
            'difference' => round($s->amount - $postedAp, 2),
            'createdAt' => self::time($s->createdAt),
            'updatedAt' => self::time($s->updatedAt),
        ];
    }

    /** @return array<string, mixed> */
    public static function settings(Ap2Settings $s, bool $odooPostingEnabled, string $mailbox): array
    {
        return [
            'glDefault' => $s->glDefault,
            'taxDefault' => $s->taxDefault,
            'escalationDays' => $s->escalationDays,
            'batchCashLimit' => $s->batchCashLimit,
            'approvalExpiryDays' => $s->approvalExpiryDays,
            'paymentProposalsEnabled' => $s->paymentProposalsEnabled,
            'taxCodeMap' => (object) $s->taxCodeMap,
            'mailCutoffDate' => $s->mailCutoffDate,
            'currencies' => Ap2Invoice::CURRENCIES,
            'odooPostingEnabled' => $odooPostingEnabled,
            'mailbox' => $mailbox,
        ];
    }

    /**
     * @param list<Ap2MailAttachment> $attachments
     * @param string|null $statementId the statement linked to this mail, if any
     * @return array<string, mixed>
     */
    public static function mail(Ap2Mail $m, array $attachments, ?string $statementId, bool $withBody = false): array
    {
        $out = [
            'id' => $m->id,
            'supplierId' => $m->supplierId,
            'invoiceId' => $m->invoiceId,
            'receivedAt' => self::time($m->receivedAt),
            'subject' => $m->subject,
            'bodyPreview' => $m->bodyPreview,
            'category' => $m->category,
            'owner' => self::person($m->owner),
            'status' => $m->status,
            'read' => $m->read,
            'due' => $m->due,
            'from' => $m->from,
            'cc' => $m->cc,
            'replyTo' => $m->replyTo,
            'recordHistory' => $m->recordHistory,
            'draft' => $m->draft,
            'draftReviewed' => $m->draftReviewed,
            'draftAttachmentIds' => $m->draftAttachmentIds,
            'attachments' => array_map(static fn (Ap2MailAttachment $a): array => ['id' => $a->id, 'name' => $a->name, 'size' => $a->size, 'contentType' => $a->contentType], $attachments),
            'sent' => $m->sent,
            'sentAt' => $m->sentAt === null ? null : self::time($m->sentAt),
            'approvalId' => $m->approvalId,
            'statementId' => $statementId,
            'aiSummary' => $m->aiSummary,
            'aiConfidence' => $m->aiConfidence,
            'createdAt' => self::time($m->createdAt),
            'updatedAt' => self::time($m->updatedAt),
        ];
        if ($withBody) {
            $out['body'] = $m->body;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function history(Ap2HistoryEntry $e): array
    {
        return [
            'id' => $e->id,
            'supplierId' => $e->supplierId,
            'invoiceId' => $e->invoiceId,
            'mailId' => $e->mailId,
            'type' => $e->type,
            'detail' => $e->detail,
            'actor' => $e->actor->toArray(),
            'at' => self::time($e->at),
            'extra' => (object) $e->extra,
        ];
    }
}
