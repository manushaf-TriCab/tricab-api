<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\Service;

use Throwable;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportApproval;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportDetails;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportNotice;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportNotificationSettings;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportVisibility;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportAssigneeDirectory;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportNotifier;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportRepository;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportPublicId;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportStatus;

/**
 * Legacy Customer Contact mail, sent after the business row has committed.
 * A delivery failure is logged and does not undo the mutation. Reopen and
 * ICAR signature capture send nothing, matching the legacy handlers.
 */
final class CustomerContactReportNotifications
{
    public function __construct(
        private readonly CustomerContactReportNotifier $notifier,
        private readonly CustomerContactReportRepository $reports,
        private readonly CustomerContactReportAssigneeDirectory $staff,
        private readonly CustomerContactReportNotificationSettings $settings,
    ) {
    }

    public function reportSubmitted(CustomerContactReportDetails $report): void
    {
        $reference = self::reference($report);
        $this->deliver(new CustomerContactReportNotice(
            event: 'submitted_receipt',
            publicId: $report->publicId,
            recipients: self::addresses([$report->createdByEmail]),
            subject: 'Customer Contact ' . $reference . ' — received',
            heading: $reference,
            intro: 'Your Customer Contact has been logged as reference ' . $reference . '. A CC number will be assigned and sent to you shortly.',
            fields: $this->intake($report),
            linkUrl: null,
            linkLabel: '',
        ));

        $coordinator = $this->coordinatorEmail();
        $customer = trim($report->customerCode) !== '' ? $report->customerCode : 'no customer code';
        $this->deliver(new CustomerContactReportNotice(
            event: 'submitted_coordinator',
            publicId: $report->publicId,
            recipients: self::addresses([$coordinator]),
            subject: 'New Customer Contact ' . $reference . ' — ' . $customer,
            heading: $reference,
            intro: 'Raised by ' . self::person($report->createdByName, $report->createdByEmail) . ' (' . $report->createdByEmail . ').',
            fields: $this->intake($report),
            linkUrl: $this->reportUrl($report->publicId),
            linkLabel: 'Open this Customer Contact',
        ));
    }

    public function reportAssigned(?CustomerContactReportDetails $before, CustomerContactReportDetails $after): void
    {
        $reference = self::reference($after);
        $fields = [
            'CC number' => $reference,
            'Route' => self::routeLabel($after->route),
            'Error code' => self::text($after->errorCode),
            'Assigned by' => self::text($after->assignedBy),
        ] + $this->core($after);
        $this->deliver(new CustomerContactReportNotice(
            event: 'assigned',
            publicId: $after->publicId,
            recipients: self::addresses([$after->assignedToEmail]),
            subject: 'Customer Contact ' . $reference . ' — assigned to you',
            heading: 'Assigned to you',
            intro: 'A Customer Contact has been assigned to you by the quality team.',
            fields: $fields,
            linkUrl: $this->reportUrl($after->publicId),
            linkLabel: 'Open this Customer Contact',
            queueUrl: $this->queueUrl(),
        ));

        if ($before !== null && $before->status === CustomerContactReportStatus::SUBMITTED) {
            $this->deliver(new CustomerContactReportNotice(
                event: 'numbered',
                publicId: $after->publicId,
                recipients: self::addresses([$after->createdByEmail]),
                subject: 'Customer Contact ' . $reference . ' — number assigned',
                heading: 'Number assigned',
                intro: 'Your Customer Contact has been reviewed and given a CC number. It is now with the person handling it.',
                fields: [
                    'CC number' => $reference,
                    'Status' => self::statusLabel($after->status),
                ] + $this->core($after),
                linkUrl: $this->reportUrl($after->publicId),
                linkLabel: 'View your Customer Contact',
            ));
        }
    }

    public function reportCompleted(CustomerContactReportDetails $report): void
    {
        $reference = self::reference($report);
        $this->deliver(new CustomerContactReportNotice(
            event: 'completed',
            publicId: $report->publicId,
            recipients: self::addresses([$this->coordinatorEmail()]),
            subject: 'Customer Contact ' . $reference . ' — completed',
            heading: 'Ready to close',
            intro: 'The person assigned to this Customer Contact has marked it complete. It is ready for you to review and close.',
            fields: [
                'CC number' => $reference,
                'Route' => self::routeLabel($report->route),
                'Completed by' => self::text($report->assignedToName ?? $report->assignedTo),
            ] + $this->core($report) + [
                'Action taken' => self::text($report->assigneeActionTaken),
                'Findings' => self::text($report->assigneeFindings),
            ],
            linkUrl: $this->reportUrl($report->publicId),
            linkLabel: 'Review and close',
        ));
    }

    public function reportClosed(CustomerContactReportDetails $report): void
    {
        $reference = self::reference($report);
        $this->deliver(new CustomerContactReportNotice(
            event: 'closed',
            publicId: $report->publicId,
            recipients: self::addresses([$report->createdByEmail]),
            subject: 'Customer Contact ' . $reference . ' — closed',
            heading: 'Closed',
            intro: 'This Customer Contact has been closed.',
            fields: ['CC number' => $reference] + $this->core($report) + [
                'Outcome' => self::text($report->assigneeActionTaken),
            ],
            linkUrl: $this->reportUrl($report->publicId),
            linkLabel: 'View this Customer Contact',
        ));
    }

    public function approvalRequested(CustomerContactReportDetails $report, CustomerContactReportApproval $approval): void
    {
        $reference = self::reference($report);
        $label = self::kindLabel($approval->kind);
        $task = $approval->kind === 'settlement';
        $heading = $task ? $label . ' needed' : $label . ' approval needed';
        $intro = $task
            ? 'A credit on this Customer Contact has been approved and now needs its ' . strtolower($label) . '. Attach it on the Customer Contact.'
            : 'A ' . strtolower($label) . ' on this Customer Contact needs your approval. Nothing happens on it until you decide.';
        $this->deliver(new CustomerContactReportNotice(
            event: 'approval_requested',
            publicId: $report->publicId,
            recipients: self::addresses([$approval->approverEmail]),
            subject: 'Customer Contact ' . $reference . ' — ' . strtolower($heading),
            heading: $heading,
            intro: $intro,
            fields: $this->approvalFields($report, $approval, false),
            linkUrl: $this->reportUrl($report->publicId),
            linkLabel: $task ? 'Attach credit note' : 'Approve or decline',
        ));
    }

    /**
     * The credit changed after someone acted on it. It is back with its approver,
     * and any Finance step was removed. Live: cc_credit_amended.
     */
    public function creditAmended(CustomerContactReportDetails $report, CustomerContactReportApproval $approval, string $by): void
    {
        $reference = self::reference($report);
        $heading = 'Credit amended, approval needed again';
        $this->deliver(new CustomerContactReportNotice(
            event: 'credit_amended',
            publicId: $report->publicId,
            recipients: self::addresses([$approval->approverEmail]),
            subject: 'Customer Contact ' . $reference . ' — credit amended',
            heading: $heading,
            intro: 'The orders, lines or credit on this Customer Contact were amended by ' . $by . ' after they were reviewed. The credit is waiting for your approval again.',
            fields: $this->approvalFields($report, $approval, false),
            linkUrl: $this->reportUrl($report->publicId),
            linkLabel: 'Approve or decline',
        ));
    }

    public function approvalDecided(CustomerContactReportDetails $report, CustomerContactReportApproval $approval): void
    {
        $reference = self::reference($report);
        $label = self::kindLabel($approval->kind);
        $approved = $approval->status === 'approved';
        $task = $approval->kind === 'settlement';
        $verb = $approved ? ($task ? 'raised' : 'approved') : 'declined';
        if ($approved && $task) {
            $intro = 'The ' . strtolower($label) . ' for this Customer Contact has been raised. Open the Customer Contact to download a copy.';
        } elseif ($approved && $approval->kind === 'credit') {
            $intro = 'The credit on this Customer Contact has been approved. Accounts, please raise it against the invoice below.';
        } elseif ($approved) {
            $intro = 'The ' . strtolower($label) . ' on this Customer Contact has been approved. The works order is raised separately.';
        } else {
            $intro = 'The ' . strtolower($label) . ' on this Customer Contact has been declined. No further action is taken on it.';
        }

        $extra = [];
        if ($approved && $approval->kind === 'credit') {
            $extra[] = $this->settings->accountsMailbox;
        }
        $this->deliver(new CustomerContactReportNotice(
            event: 'approval_decided',
            publicId: $report->publicId,
            recipients: $this->involved($report, $extra),
            subject: 'Customer Contact ' . $reference . ' — ' . strtolower($label) . ' ' . $verb,
            heading: $label . ' ' . $verb,
            intro: $intro,
            fields: $this->approvalFields($report, $approval, true),
            linkUrl: $this->reportUrl($report->publicId),
            linkLabel: 'View this Customer Contact',
        ));
    }

    public function settlementCompleted(string $publicId): void
    {
        $report = $this->reports->findVisible(
            CustomerContactReportPublicId::fromString($publicId),
            new CustomerContactReportVisibility(true, 0, ''),
        );
        if ($report === null) {
            return;
        }
        foreach ($this->reports->approvalsFor($report->publicId) as $approval) {
            if ($approval->kind === 'settlement') {
                $this->approvalDecided($report, $approval);

                return;
            }
        }
    }

    private function deliver(CustomerContactReportNotice $notice): void
    {
        if ($notice->recipients === []) {
            return;
        }
        try {
            $this->notifier->send($notice);
        } catch (Throwable $failure) {
            error_log((json_encode([
                'event' => 'customer_contact_notification_failed',
                'notification' => $notice->event,
                'publicId' => $notice->publicId,
                'exception' => $failure::class,
            ], JSON_UNESCAPED_SLASHES)) ?: '{"event":"customer_contact_notification_failed"}');
        }
    }

    /**
     * @return array<string, string>
     */
    private function intake(CustomerContactReportDetails $report): array
    {
        return [
            'Report date' => $report->reportDate->format('Y-m-d'),
            'Location' => $report->location,
        ] + $this->core($report);
    }

    /**
     * @return array<string, string>
     */
    private function core(CustomerContactReportDetails $report): array
    {
        return [
            'Customer' => $report->customerCode,
            'Contact' => self::text($report->customerContact),
            'Product' => $report->productCode,
            'Problem' => $report->description,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function approvalFields(
        CustomerContactReportDetails $report,
        CustomerContactReportApproval $approval,
        bool $decided,
    ): array {
        $reference = self::reference($report);
        $head = ['CC number' => $reference];
        if ($decided) {
            $approved = $approval->status === 'approved';
            $head[$approved ? 'Approved by' : 'Declined by'] = self::text($approval->approverName);
            if (!$approved || trim((string) $approval->note) !== '') {
                $head[$approved ? 'Note' : 'Reason'] = self::text($approval->note);
            }
        } else {
            $head['Requested by'] = self::text($approval->requestedBy);
        }
        $fields = $head + $this->core($report) + [
            'Order / Invoice No' => self::text($report->tricabRefTrace),
        ];
        if ($approval->kind !== 'credit') {
            return $fields;
        }

        return $fields + [
            'Value Ex GST' => self::money($report->valueExGst),
            '30% Restocking Fee' => self::money($report->restockingAmount),
            'Sub Total' => self::money($report->subTotal),
            'GST' => self::money($report->gst),
            'Credit Total' => self::money($report->creditTotal),
        ];
    }

    /**
     * @param list<string> $extra
     * @return list<string>
     */
    private function involved(CustomerContactReportDetails $report, array $extra): array
    {
        $addresses = [
            $report->createdByEmail,
            (string) $report->assignedToEmail,
            (string) $this->coordinatorEmail(),
        ];
        foreach (preg_split('/[\s,;]+/', (string) $report->tricabContact) ?: [] as $token) {
            if (trim($token) === '') {
                continue;
            }
            $person = $this->staff->findActiveStaffByLogin($token);
            if ($person !== null) {
                $addresses[] = $person->email;
            }
        }
        foreach ($this->reports->approvalsFor($report->publicId) as $approval) {
            $addresses[] = $approval->approverEmail;
        }
        foreach ($extra as $address) {
            $addresses[] = $address;
        }

        return self::addresses($addresses);
    }

    private function coordinatorEmail(): ?string
    {
        $login = strtolower(trim($this->settings->coordinatorLogin));
        if ($login === '') {
            return null;
        }
        $person = $this->staff->findActiveStaffByLogin($login);

        return $person?->email;
    }

    private function reportUrl(string $publicId): ?string
    {
        $base = rtrim(trim($this->settings->webBaseUrl), '/');
        if ($base === '') {
            return null;
        }

        return $base . '/customer-contacts/' . rawurlencode($publicId);
    }

    private function queueUrl(): ?string
    {
        $base = rtrim(trim($this->settings->webBaseUrl), '/');
        if ($base === '') {
            return null;
        }

        return $base . '/customer-contacts';
    }

    /**
     * @param list<?string> $candidates
     * @return list<string>
     */
    private static function addresses(array $candidates): array
    {
        $out = [];
        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }
            $email = strtolower(trim($candidate));
            if ($email === '' || !str_contains($email, '@') || str_contains($email, ' ')) {
                continue;
            }
            if (!in_array($email, $out, true)) {
                $out[] = $email;
            }
        }

        return $out;
    }

    private static function reference(CustomerContactReportDetails $report): string
    {
        $number = trim((string) $report->ccNumber);

        return $number !== '' ? $number : $report->publicId;
    }

    private static function routeLabel(?string $route): string
    {
        return match ($route) {
            'branch' => 'Branch action',
            'icar' => 'ICAR — internal investigation',
            default => 'Not yet routed',
        };
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            CustomerContactReportStatus::SUBMITTED => 'Awaiting review',
            CustomerContactReportStatus::ASSIGNED => 'In progress',
            CustomerContactReportStatus::COMPLETED => 'Completed, awaiting close',
            CustomerContactReportStatus::CLOSED => 'Closed',
            default => $status,
        };
    }

    private static function kindLabel(string $kind): string
    {
        return match ($kind) {
            'credit' => 'Credit',
            'rework' => 'Rework',
            'settlement' => 'Credit note',
            default => $kind,
        };
    }

    private static function text(?string $value): string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? '—' : $trimmed;
    }

    private static function money(?string $decimal): string
    {
        $trimmed = trim((string) $decimal);
        if ($trimmed === '' || !preg_match('/^\d+(\.\d{1,2})?$/', $trimmed)) {
            return '—';
        }
        $parts = explode('.', $trimmed);
        $whole = number_format((int) $parts[0]);
        $cents = str_pad($parts[1] ?? '00', 2, '0');

        return '$' . $whole . '.' . $cents;
    }

    private static function person(string $name, string $email): string
    {
        $trimmed = trim($name);

        return $trimmed !== '' ? $trimmed : $email;
    }
}
