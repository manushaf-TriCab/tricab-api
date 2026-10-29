<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportDetails;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportListCriteria;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportVisibility;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportRepository;
use TriCab\Core\CustomerContactReport\Module;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Shared\Time\Clock;

/**
 * CSV of the register, same filters and same visibility as the list. A person
 * without READ_ALL gets only their own rows. Column order follows the paper
 * register (live cc_export_csv). Office columns are for READ_ALL callers.
 *
 * Cells that start with = + - @ or a tab/CR are prefixed with an apostrophe so
 * a spreadsheet does not run them as formulas. Live does not do this.
 */
final class ExportCustomerContactReportsService
{
    private const PAGE = 100;

    private const MAX_ROWS = 20000;

    private const ROUTES = ['branch' => 'Branch action', 'icar' => 'ICAR — internal investigation'];

    private const STATUSES = [
        'submitted' => 'Awaiting review',
        'assigned' => 'In progress',
        'completed' => 'Completed, awaiting close',
        'closed' => 'Closed',
    ];

    public function __construct(
        private readonly CustomerContactReportRepository $reports,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array{filename: string, body: string, rows: int, truncated: bool}
     */
    public function execute(AuthenticatedUser $actor, CustomerContactReportListCriteria $criteria): array
    {
        $readAll = in_array(Module::PERMISSION_READ_ALL, $actor->permissions, true);
        $visibility = new CustomerContactReportVisibility($readAll, $actor->userId, $actor->emailAddress);
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));

        $head = ['CC No.', 'Raised', 'Raised By', 'Location', 'Customer', 'Contact', 'Product', 'Order', 'Invoice',
            'Problem', 'Area of Complaint', 'Status', 'Route', 'Investigated By', 'Age (days)'];
        if ($readAll) {
            $head = array_merge($head, ['Trace No', 'Root Cause', 'Credit Type', 'Credit Total', 'Assessed By', 'Credit Raised', 'Closed By', 'Closed']);
        }

        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            return ['filename' => self::filename($now), 'body' => '', 'rows' => 0, 'truncated' => false];
        }
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $head);

        $rows = 0;
        $truncated = false;
        for ($page = 1;; $page++) {
            $slice = $this->reports->list(
                new CustomerContactReportListCriteria(
                    $page,
                    self::PAGE,
                    $criteria->status,
                    $criteria->month,
                    $criteria->year,
                    $criteria->search,
                    $criteria->customer,
                    $criteria->assignee,
                    $criteria->location,
                    $criteria->errorCode,
                ),
                $visibility,
            );
            foreach ($slice->items as $report) {
                if ($rows >= self::MAX_ROWS) {
                    $truncated = true;
                    break 2;
                }
                fputcsv($handle, array_map(self::cell(...), $this->line($report, $readAll, $now)));
                $rows++;
            }
            if (count($slice->items) < self::PAGE) {
                break;
            }
        }
        rewind($handle);
        $body = (string) stream_get_contents($handle);
        fclose($handle);

        return ['filename' => self::filename($now), 'body' => $body, 'rows' => $rows, 'truncated' => $truncated];
    }

    /**
     * @return list<string|int>
     */
    private function line(CustomerContactReportDetails $r, bool $office, DateTimeImmutable $now): array
    {
        $age = $r->status === 'closed' ? '' : max(0, (int) $r->reportDate->diff($now)->format('%r%a'));
        $line = [
            $r->ccNumber ?? ('#' . $r->reportId),
            $r->reportDate->format('Y-m-d'),
            $r->createdByName,
            $r->location,
            $r->customerCode,
            (string) $r->customerContact,
            $r->productCode,
            (string) $r->orderNo,
            (string) $r->invoiceNo,
            $r->description,
            (string) $r->errorCode,
            self::STATUSES[$r->status] ?? $r->status,
            $r->route !== null ? (self::ROUTES[$r->route] ?? $r->route) : '',
            (string) $r->assignedToName,
            $age,
        ];
        if ($office) {
            $line = array_merge($line, [
                (string) $r->traceNum,
                (string) $r->rootCause,
                (string) $r->creditType,
                (string) $r->creditTotal,
                (string) $r->ccAssessedBy,
                (string) $r->creditRaised,
                (string) $r->closedBy,
                $r->closedAtUtc?->format('Y-m-d') ?? '',
            ]);
        }

        return $line;
    }

    private static function cell(string|int $value): string
    {
        $text = (string) $value;

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $text : $text;
    }

    private static function filename(DateTimeImmutable $now): string
    {
        return 'customer-contacts-' . $now->format('Ymd') . '.csv';
    }
}
