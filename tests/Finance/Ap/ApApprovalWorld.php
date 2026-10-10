<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Api\Finance\Ap\InMemoryApApprovalStore;
use TriCab\Api\Finance\Ap\InMemoryApApproverDirectory;
use TriCab\Core\Finance\Ap\Domain\ApActor;
use TriCab\Core\Finance\Ap\Domain\ApMailboxScope;
use TriCab\Core\Finance\Ap\Service\ApApprovalService;
use TriCab\Core\Finance\Ap\Service\ApMailService;
use TriCab\Core\Finance\Ap\Service\ApTime;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Mail\FinanceMailService;

/**
 * The approvals service over the in-memory stores and {@see ApMailWorld}'s fake transport (nothing leaves the process), with a
 * fixed clock (2026-10-09 01:02:03 UTC). Two Approvers (Ann, user 21, and Bob, user 22) are in the directory; the Preparer is Dee
 * (user 7); `ap@example.test` is the configured mailbox and may be sent from. Batch ids are `b000...001`, `b000...002`.
 */
final class ApApprovalWorld
{
    public const ORIGIN = 'https://site.example.test';

    public ApMailWorld $mail;

    public InMemoryApApprovalStore $store;

    public InMemoryApApproverDirectory $directory;

    public ApApprovalService $service;

    private int $ids = 0;

    public function __construct(bool $sending = true, ?string $mailbox = null)
    {
        $this->mail = new ApMailWorld($sending);
        $this->store = new InMemoryApApprovalStore($this->mail->register->invoices, $this->mail->outbound, $this->mail->ledger);
        $this->directory = new InMemoryApApproverDirectory();
        $this->directory->add(22, 'Bob Approver', 'Bob@Example.test');
        $this->directory->add(21, 'Ann Approver', 'ann@example.test');
        $this->service = $this->serviceFor($sending, $mailbox);
    }

    /** The service with the sending switch as given, sharing the stores (what a restart with the switch changed would see). */
    public function serviceFor(bool $sending, ?string $mailbox = null): ApApprovalService
    {
        $mailService = $mailbox === null ? $this->mail->serviceFor($sending) : $this->mailFrom($sending, $mailbox);

        return new ApApprovalService(
            $this->store,
            $this->mail->register->invoices,
            $this->directory,
            $this->mail->register->audit,
            $mailService,
            new ApTime($this->mail->register->clock, 'Australia/Melbourne'),
            self::ORIGIN . '/',
            function (): string {
                return 'b' . str_pad((string) ++$this->ids, 31, '0', STR_PAD_LEFT);
            },
        );
    }

    /** The n-th id the service generated: a request makes the batch id first (`id(1)`), then one per item. */
    public static function id(int $n): string
    {
        return 'b' . str_pad((string) $n, 31, '0', STR_PAD_LEFT);
    }

    private function mailFrom(bool $sending, string $mailbox): ApMailService
    {
        $n = 0;
        $mail = new FinanceMailService(
            $this->mail->registry,
            $this->mail->ledger,
            $this->mail->transport,
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-09 02:03:04', new DateTimeZone('UTC')),
            static function () use (&$n): string {
                return 'x' . str_pad((string) ++$n, 31, '0', STR_PAD_LEFT);
            },
        );

        return new ApMailService(
            $this->mail->register->invoices,
            $this->mail->emails,
            $this->mail->register->attachments,
            $this->mail->outbound,
            $this->mail->register->audit,
            $mail,
            $this->mail->ledger,
            $this->mail->registry,
            new ApTime($this->mail->register->clock, 'Australia/Melbourne'),
            $mailbox,
            $sending,
        );
    }

    /**
     * Dee, who may ask for approval.
     *
     * @param list<string>|null $mailboxes null for everyone
     */
    public static function preparer(?array $mailboxes = null, string $email = 'Dee@Example.test', int $userId = 7): ApActor
    {
        return new ApActor($userId, 'Dee Preparer', $email, [Module::PERMISSION_AP_INVOICES_READ, Module::PERMISSION_AP_APPROVALS_PREPARE], self::scope($mailboxes));
    }

    /**
     * Ann, who may decide.
     *
     * @param list<string>|null $mailboxes null for everyone
     */
    public static function approver(?array $mailboxes = null, int $userId = 21, string $name = 'Ann Approver', string $email = 'Ann@Example.test'): ApActor
    {
        return new ApActor($userId, $name, $email, [Module::PERMISSION_AP_INVOICES_READ, Module::PERMISSION_AP_APPROVALS_DECIDE], self::scope($mailboxes));
    }

    /** An Administrator: holds every permission, so may prepare and decide the same batch. */
    public static function administrator(int $userId = 31): ApActor
    {
        return new ApActor(
            $userId,
            'Ada Admin',
            'ada@example.test',
            [Module::PERMISSION_AP_INVOICES_READ, Module::PERMISSION_AP_APPROVALS_PREPARE, Module::PERMISSION_AP_APPROVALS_DECIDE, Module::PERMISSION_AP_SETTINGS_MANAGE],
            ApMailboxScope::unrestricted(),
            true,
        );
    }

    /**
     * @param list<string>|null $mailboxes
     */
    private static function scope(?array $mailboxes): ApMailboxScope
    {
        return $mailboxes === null ? ApMailboxScope::unrestricted() : ApMailboxScope::limitedTo($mailboxes);
    }
}
