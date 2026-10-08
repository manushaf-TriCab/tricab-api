<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ar\Migration;

use DateTimeImmutable;
use TriCab\Api\Finance\Ar\AzureSqlArOptionStore;
use TriCab\Api\Finance\Ar\AzureSqlArRecordStore;
use TriCab\Api\Finance\Ar\AzureSqlArSupport;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Finance\Ar\Domain\ArOption;
use TriCab\Core\Finance\Ar\Domain\ArRecord;
use TriCab\Core\Finance\Ar\Migration\ArMigratedAttachment;
use TriCab\Core\Finance\Ar\Migration\ArMigratedLine;
use TriCab\Core\Finance\Ar\Migration\ArMigratedScanRun;
use TriCab\Core\Finance\Ar\Migration\ArMigrationTarget;

/**
 * The Tri-AR tables in `finance` (`create-finance-ar-schema.sql`) as the migration writes them. Records and options
 * go through the register's and the option list's own stores, so they are written exactly as the platform writes
 * them; the attachment rows with their payment header, the lines, the scan runs and the tombstones are written here
 * because no store writes them whole. The transaction is the one the stores share on this connection.
 */
final class AzureSqlArMigrationTarget extends AzureSqlArSupport implements ArMigrationTarget
{
    private const TABLES = ['ar_email_records', 'ar_email_attachments', 'ar_remittance_lines', 'ar_scan_runs', 'ar_option_lists', 'ar_deleted_messages'];

    private readonly AzureSqlArRecordStore $records;

    private readonly AzureSqlArOptionStore $optionStore;

    public function __construct(SqlConnection $connection)
    {
        parent::__construct($connection);
        $this->records = new AzureSqlArRecordStore($connection);
        $this->optionStore = new AzureSqlArOptionStore($connection);
    }

    public function counts(): array
    {
        $out = [];
        foreach (self::TABLES as $table) {
            $rows = $this->connection->fetchAll('SELECT COUNT(*) AS [n] FROM [finance].[' . $table . ']');
            $out[$table] = self::int($rows[0]['n'] ?? 0);
        }

        return $out;
    }

    public function hasRecord(string $id): bool
    {
        return $this->records->exists($id);
    }

    public function recordHoldingMessage(string $messageId): ?string
    {
        $rows = $this->connection->fetchAll(
            'SELECT TOP 1 [id] FROM [finance].[ar_email_records] WHERE [source_message_id] = :id',
            ['id' => $messageId],
        );

        return $rows === [] ? null : self::id($rows[0]['id']);
    }

    public function attachmentIdsOf(string $recordId): array
    {
        return array_map(
            static fn (array $row): string => self::id($row['id']),
            $this->connection->fetchAll('SELECT [id] FROM [finance].[ar_email_attachments] WHERE [email_record_id] = :id', ['id' => $recordId]),
        );
    }

    public function options(string $listName): array
    {
        return $this->optionStore->options($listName);
    }

    public function hasScanRun(string $id): bool
    {
        return $this->connection->fetchAll('SELECT 1 AS [there] FROM [finance].[ar_scan_runs] WHERE [id] = :id', ['id' => $id]) !== [];
    }

    public function hasTombstone(string $messageId): bool
    {
        return $this->connection->fetchAll('SELECT 1 AS [there] FROM [finance].[ar_deleted_messages] WHERE [source_message_id] = :id', ['id' => $messageId]) !== [];
    }

    public function latestWatermarks(): array
    {
        $out = [];
        foreach ($this->connection->fetchAll(
            'SELECT [mailbox], MAX([watermark]) AS [watermark] FROM [finance].[ar_scan_runs]
              WHERE [status] = N\'completed\' AND [watermark] IS NOT NULL GROUP BY [mailbox]',
        ) as $row) {
            $out[self::text($row['mailbox'])] = self::when($row['watermark'], 'watermark');
        }

        return $out;
    }

    public function insertRecord(ArRecord $record): void
    {
        $this->records->insert($record);
    }

    public function insertAttachment(ArMigratedAttachment $a): void
    {
        $this->connection->execute(
            'INSERT INTO [finance].[ar_email_attachments]
                ([id], [email_record_id], [attachment_id], [is_remittance], [payer_name], [payment_date], [payment_reference], [currency],
                 [total_amount], [extraction_confidence], [reconciles], [extraction_status], [extraction_error], [notes], [extracted_at],
                 [created_date], [updated_date])
             VALUES (:id, :record, :file, :remittance, :payer, :paid, :reference, :currency,
                     :total, :confidence, :reconciles, :status, :error, :notes, :extracted, :created, :updated)',
            [
                'id' => $a->id,
                'record' => $a->recordId,
                'file' => $a->fileId,
                'remittance' => $a->isRemittance ? 1 : 0,
                'payer' => $a->payerName,
                'paid' => $a->paymentDate,
                'reference' => $a->paymentReference,
                'currency' => $a->currency,
                'total' => $a->totalAmount,
                'confidence' => $a->extractionConfidence,
                'reconciles' => $a->reconciles === null ? null : ($a->reconciles ? 1 : 0),
                'status' => $a->extractionStatus,
                'error' => $a->extractionError,
                'notes' => $a->notes,
                'extracted' => self::atOrNull($a->extractedAt),
                'created' => self::at($a->createdAt),
                'updated' => self::at($a->updatedAt),
            ],
        );
    }

    public function insertLine(ArMigratedLine $l): void
    {
        $this->connection->execute(
            'INSERT INTO [finance].[ar_remittance_lines]
                ([id], [email_attachment_id], [email_record_id], [line_no], [invoice_number], [invoice_date], [gross_amount], [discount_amount], [paid_amount], [created_date])
             VALUES (:id, :attachment, :record, :line, :invoice, :date, :gross, :discount, :paid, :created)',
            [
                'id' => $l->id,
                'attachment' => $l->attachmentId,
                'record' => $l->recordId,
                'line' => $l->lineNo,
                'invoice' => $l->invoiceNumber,
                'date' => $l->invoiceDate,
                'gross' => $l->grossAmount,
                'discount' => $l->discountAmount,
                'paid' => $l->paidAmount,
                'created' => self::at($l->createdAt),
            ],
        );
    }

    public function insertScanRun(ArMigratedScanRun $r): void
    {
        $this->connection->execute(
            'INSERT INTO [finance].[ar_scan_runs]
                ([id], [started_at], [finished_at], [mailbox], [status], [watermark], [total_fetched], [new_records], [skipped_records],
                 [failed_enrichments], [errors], [from_date], [to_date], [requested_by_user_id], [requested_by], [total_messages],
                 [processed_messages], [claim_expires_at], [created_date])
             VALUES (:id, :started, :finished, :mailbox, :status, :watermark, :fetched, :new, :skipped,
                     :failed, :errors, :from, :to, :byUser, :by, :total, :processed, NULL, :created)',
            [
                'id' => $r->id,
                'started' => self::at($r->startedAt),
                'finished' => self::atOrNull($r->finishedAt),
                'mailbox' => $r->mailbox,
                'status' => $r->status,
                'watermark' => self::atOrNull($r->watermark),
                'fetched' => $r->totalFetched,
                'new' => $r->newRecords,
                'skipped' => $r->skippedRecords,
                'failed' => $r->failedEnrichments,
                'errors' => $r->errors,
                'from' => $r->fromDate,
                'to' => $r->toDate,
                'byUser' => $r->requestedByUserId,
                'by' => $r->requestedBy,
                'total' => $r->totalMessages,
                'processed' => $r->processedMessages,
                'created' => self::at($r->createdAt),
            ],
        );
    }

    public function insertOption(ArOption $option): void
    {
        $this->optionStore->insertOption($option);
    }

    public function insertTombstone(string $messageId, DateTimeImmutable $at): void
    {
        $this->connection->execute(
            'INSERT INTO [finance].[ar_deleted_messages] ([source_message_id], [deleted_date]) VALUES (:id, :at)',
            ['id' => $messageId, 'at' => self::at($at)],
        );
    }
}
