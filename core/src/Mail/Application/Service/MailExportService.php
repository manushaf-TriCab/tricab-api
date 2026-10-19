<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\Service;

use TriCab\Core\Mail\Application\DTO\ExportQuery;
use TriCab\Core\Mail\Application\DTO\ExportRow;
use TriCab\Core\Mail\Application\DTO\MailTime;
use TriCab\Core\Mail\Contract\MailStore;
use TriCab\Core\Mail\Domain\Exception\MailActionForbidden;
use TriCab\Core\Mail\Domain\MailActor;
use TriCab\Core\Mail\Domain\Service\MailAccessPolicy;

/**
 * The labelled set as CSV (plan I3.3), for the M5 accuracy numbers: one row per assessed thread, skipped
 * ones flagged, with what the classifier said next to what a person decided. No subject, preview or
 * address leaves here, only the sender's domain.
 *
 * The route holds `MAIL.REVIEW`; this also needs `MAIL.ACCESS` (as {@see MailAccessPolicy::canExport()}
 * says), so a reviewer who cannot open the inbox cannot pull it either. Text cells that a spreadsheet
 * would read as a formula (`=`, `+`, `-`, `@`, tab, CR first) get a leading apostrophe.
 */
final class MailExportService
{
    public const HEADERS = [
        'thread_id',
        'internet_message_id',
        'received_at',
        'sender_domain',
        'classifier_label',
        'confidence',
        'model',
        'classifier_version',
        'skipped',
        'injection_suspected',
        'decided_label',
        'decision_kind',
        'decided_at',
        'decided_by',
    ];

    public function __construct(private readonly MailStore $store)
    {
    }

    public function csv(MailActor $actor, ExportQuery $query): string
    {
        if (!MailAccessPolicy::canExport($actor)) {
            throw new MailActionForbidden('You do not have permission to export the labelled set.');
        }
        $lines = [self::line(self::HEADERS)];
        foreach ($this->store->exportRows($query) as $row) {
            $lines[] = self::line(self::cells($row));
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    /** @return list<string> */
    private static function cells(ExportRow $row): array
    {
        return [
            self::text($row->threadId),
            self::text($row->internetMessageId),
            MailTime::iso($row->receivedAt),
            self::text($row->senderDomain),
            $row->label,
            rtrim(rtrim(number_format($row->confidence, 4, '.', ''), '0'), '.') ?: '0',
            self::text($row->model),
            self::text($row->version),
            $row->skipped ? 'true' : 'false',
            $row->injectionSuspected ? 'true' : 'false',
            $row->decidedLabel ?? '',
            $row->decisionKind ?? '',
            $row->decidedAt !== null ? MailTime::iso($row->decidedAt) : '',
            self::text($row->decidedBy ?? ''),
        ];
    }

    /** Neutralises a cell a spreadsheet would run as a formula. */
    private static function text(string $value): string
    {
        return $value !== '' && strpbrk($value[0], "=+-@\t\r") !== false ? "'" . $value : $value;
    }

    /** @param list<string> $cells */
    private static function line(array $cells): string
    {
        return implode(',', array_map(static fn (string $c): string => preg_match('/[",\r\n]/', $c) === 1 ? '"' . str_replace('"', '""', $c) . '"' : $c, $cells));
    }
}
