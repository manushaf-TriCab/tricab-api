<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar\Migration;

use PDO;

/**
 * An invented copy of the old Tri-AR app's data, built by the test itself: a SQLite file with the source's tables
 * (as `server/store.py` creates them) and an attachments folder with tiny made-up files. Customers are invented,
 * senders are at `example.test`, and the "PDFs" are a few made-up bytes. Nothing here is real.
 */
final class InventedTriArData
{
    public const BYTES = "%PDF-1.4\n% invented remittance advice, not a real document\n%%EOF\n";

    public readonly string $dir;

    public readonly string $database;

    public readonly string $attachments;

    private ?PDO $pdo;

    private int $next = 0;

    public function __construct()
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ar-migration-' . bin2hex(random_bytes(6));
        $this->attachments = $this->dir . DIRECTORY_SEPARATOR . 'attachments';
        mkdir($this->attachments, 0777, true);
        $this->database = $this->dir . DIRECTORY_SEPARATOR . 'ar.sqlite3';
        $this->pdo = new PDO('sqlite:' . $this->database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec(self::SCHEMA);
    }

    public function __destruct()
    {
        $this->pdo = null;
        self::remove($this->dir);
    }

    /** A new 32-hex lower-case id. */
    public function id(): string
    {
        return str_pad(dechex(++$this->next), 32, '0', STR_PAD_LEFT);
    }

    /**
     * @param array<string, mixed> $over any column by name
     * @return string the record's id
     */
    public function record(array $over = []): string
    {
        $id = is_string($over['id'] ?? null) ? $over['id'] : $this->id();
        $this->insert('email_records', $over + [
            'id' => $id,
            'email_date' => '2026-09-21T02:09:00Z',
            'customer_name' => 'Acme Marine Pty Ltd',
            'sender_email' => 'accounts@acme.example.test',
            'sender_name' => 'Alex Accounts',
            'subject' => 'Remittance for invoice 4471',
            'body' => 'Please find the remittance advice attached.',
            'ai_summary' => 'Customer sends a remittance advice.',
            'follow_up_category' => 'remittance_advice',
            'assigned_person' => '',
            'proposed_response_type' => 'email',
            'proposed_response' => '',
            'follow_up_status' => 'needs_review',
            'due_date' => null,
            'final_outcome' => '',
            'source_message_id' => 'AAMkAD-old-folder-id-' . $id,
            'mailbox' => 'ar@tricab.com',
            'classification_confidence' => 0.91,
            'status' => 'new',
            'assigned_sales_rep_email' => '',
            'credit_note_request_id' => null,
            'reminder_sent_at' => null,
            'deleted_at' => null,
            'deleted_by' => null,
            'created_date' => '2026-09-21T02:10:00.123456+00:00',
            'updated_date' => '2026-09-21T02:10:00.123456+00:00',
        ]);

        return $id;
    }

    /**
     * Adds an attachment row and, unless `$bytes` is null, its file under the attachments folder.
     *
     * @param array<string, mixed> $over any column by name
     * @return string the attachment row's id
     */
    public function attachment(string $recordId, array $over = [], ?string $bytes = self::BYTES): string
    {
        $id = is_string($over['id'] ?? null) ? $over['id'] : $this->id();
        $stored = is_string($over['stored_name'] ?? null) ? $over['stored_name'] : $recordId . '/remittance-' . $id . '.pdf';
        $this->insert('email_attachments', $over + [
            'id' => $id,
            'email_record_id' => $recordId,
            'file_name' => 'Remittance Advice.pdf',
            'stored_name' => $stored,
            'content_type' => 'application/pdf',
            'size_bytes' => strlen((string) $bytes),
            'is_remittance' => 1,
            'payer_name' => 'Acme Marine Pty Ltd',
            'payment_date' => '2026-09-20',
            'payment_reference' => 'EFT-998877',
            'currency' => 'AUD',
            'total_amount' => 1250.5,
            'extraction_confidence' => 0.88,
            'reconciles' => 1,
            'extraction_status' => 'extracted',
            'extraction_error' => null,
            'notes' => null,
            'extracted_at' => '2026-09-21T02:12:00+00:00',
            'created_date' => '2026-09-21T02:11:00+00:00',
            'updated_date' => '2026-09-21T02:12:00+00:00',
        ]);
        if ($bytes !== null) {
            $this->file($stored, $bytes);
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $over any column by name
     * @return string the line's id
     */
    public function line(string $attachmentId, string $recordId, array $over = []): string
    {
        $id = is_string($over['id'] ?? null) ? $over['id'] : $this->id();
        $this->insert('remittance_lines', $over + [
            'id' => $id,
            'attachment_id' => $attachmentId,
            'email_record_id' => $recordId,
            'invoice_number' => 'INV-4471',
            'invoice_date' => '2026-08-30',
            'gross_amount' => 1300.0,
            'discount_amount' => 49.5,
            'paid_amount' => 1250.5,
            'created_date' => '2026-09-21T02:12:00+00:00',
        ]);

        return $id;
    }

    /** @param array<string, mixed> $over */
    public function scanRun(array $over = []): string
    {
        $id = is_string($over['id'] ?? null) ? $over['id'] : $this->id();
        $this->insert('scan_runs', $over + [
            'id' => $id,
            'started_at' => '2026-10-06T21:00:00+00:00',
            'finished_at' => '2026-10-06T21:03:00+00:00',
            'mailbox' => 'ar@tricab.com',
            'status' => 'completed',
            'watermark' => '2026-10-06T20:58:11Z',
            'total_fetched' => 12,
            'new_records' => 9,
            'skipped_records' => 3,
            'failed_enrichments' => 0,
            'errors' => null,
            'created_date' => '2026-10-06T21:00:00+00:00',
            'from_date' => null,
            'to_date' => null,
            'requested_by' => null,
            'total_messages' => 0,
            'processed_messages' => 0,
        ]);

        return $id;
    }

    /** @param array<string, mixed> $over */
    public function option(string $list, string $value, array $over = []): void
    {
        $this->insert('option_lists', $over + [
            'list_name' => $list,
            'value' => $value,
            'label' => ucfirst(str_replace('_', ' ', $value)),
            'description' => 'Criterion for ' . $value,
            'color' => 'blue',
            'sort_order' => 0,
            'active' => 1,
            'is_system' => 0,
            'created_date' => '2026-09-01T00:00:00+00:00',
            'updated_date' => '2026-09-01T00:00:00+00:00',
        ]);
    }

    public function deleted(string $messageId, string $at = '2026-09-30T01:00:00+00:00'): void
    {
        $this->insert('deleted_messages', ['source_message_id' => $messageId, 'deleted_date' => $at]);
    }

    /** @param array<string, mixed> $over */
    public function outbound(string $recordId, array $over = []): void
    {
        $this->insert('outbound_messages', $over + [
            'id' => $this->id(), 'email_record_id' => $recordId, 'to_addresses' => '["a@example.test"]', 'subject' => 'Re: invoice',
            'body' => 'Thanks.', 'created_date' => '2026-09-22T00:00:00+00:00', 'updated_date' => '2026-09-22T00:00:00+00:00',
        ]);
    }

    /** @param array<string, mixed> $over */
    public function creditNote(array $over = []): void
    {
        $this->insert('credit_note_requests', $over + [
            'id' => $this->id(), 'customer_name' => 'Acme Marine Pty Ltd', 'sales_rep_email' => 'rep@example.test',
            'token' => bin2hex(random_bytes(8)), 'token_expires_at' => '2026-10-01T00:00:00+00:00',
            'created_date' => '2026-09-22T00:00:00+00:00', 'updated_date' => '2026-09-22T00:00:00+00:00',
        ]);
    }

    /** Writes a file under the attachments folder (or any path under the temp directory when it climbs out). */
    public function file(string $storedName, string $bytes): void
    {
        $path = $this->attachments . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storedName);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $bytes);
    }

    /** @param array<string, mixed> $row */
    private function insert(string $table, array $row): void
    {
        $columns = array_keys($row);
        $statement = $this->pdo?->prepare(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?')),
        ));
        if ($statement === null || $statement === false) {
            throw new \RuntimeException('The invented source cannot be written to.');
        }
        $statement->execute(array_values($row));
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . DIRECTORY_SEPARATOR . $entry);
                }
            }
            @rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            @unlink($path);
        }
    }

    /** The tables as `server/store.py` creates them at 6b54894 (the Bin's two columns are added by its additive migration). */
    private const SCHEMA = <<<'SQL'
CREATE TABLE email_records (
    id TEXT PRIMARY KEY, email_date TEXT NOT NULL, customer_name TEXT, sender_email TEXT NOT NULL, sender_name TEXT,
    subject TEXT NOT NULL, body TEXT, ai_summary TEXT, follow_up_category TEXT, assigned_person TEXT,
    proposed_response_type TEXT DEFAULT 'email', proposed_response TEXT, follow_up_status TEXT DEFAULT 'in_progress',
    due_date TEXT, final_outcome TEXT, source_message_id TEXT UNIQUE, mailbox TEXT DEFAULT 'ar@tricab.com',
    classification_confidence REAL, status TEXT DEFAULT 'new', assigned_sales_rep_email TEXT, credit_note_request_id TEXT,
    reminder_sent_at TEXT, deleted_at TEXT, deleted_by TEXT, created_date TEXT NOT NULL, updated_date TEXT NOT NULL
);
CREATE TABLE credit_note_requests (
    id TEXT PRIMARY KEY, email_record_id TEXT, customer_name TEXT NOT NULL, customer_email TEXT, sales_rep_name TEXT,
    sales_rep_email TEXT NOT NULL, invoice_number TEXT, credit_amount REAL DEFAULT 0, reason TEXT, original_request TEXT,
    token TEXT UNIQUE NOT NULL, token_expires_at TEXT NOT NULL, status TEXT DEFAULT 'form_sent', form_sent_date TEXT,
    submitted_date TEXT, reviewed_at TEXT, created_date TEXT NOT NULL, updated_date TEXT NOT NULL
);
CREATE TABLE scan_runs (
    id TEXT PRIMARY KEY, started_at TEXT NOT NULL, finished_at TEXT, mailbox TEXT NOT NULL, status TEXT NOT NULL, watermark TEXT,
    total_fetched INTEGER DEFAULT 0, new_records INTEGER DEFAULT 0, skipped_records INTEGER DEFAULT 0, failed_enrichments INTEGER DEFAULT 0,
    errors TEXT, created_date TEXT NOT NULL, from_date TEXT, to_date TEXT, requested_by TEXT, total_messages INTEGER DEFAULT 0,
    processed_messages INTEGER DEFAULT 0
);
CREATE TABLE email_attachments (
    id TEXT PRIMARY KEY, email_record_id TEXT NOT NULL, file_name TEXT NOT NULL, stored_name TEXT NOT NULL, content_type TEXT,
    size_bytes INTEGER DEFAULT 0, is_remittance INTEGER DEFAULT 0, payer_name TEXT, payment_date TEXT, payment_reference TEXT,
    currency TEXT DEFAULT 'AUD', total_amount REAL, extraction_confidence REAL, reconciles INTEGER, extraction_status TEXT DEFAULT 'pending',
    extraction_error TEXT, notes TEXT, extracted_at TEXT, created_date TEXT NOT NULL, updated_date TEXT NOT NULL
);
CREATE TABLE remittance_lines (
    id TEXT PRIMARY KEY, attachment_id TEXT NOT NULL, email_record_id TEXT NOT NULL, invoice_number TEXT, invoice_date TEXT,
    gross_amount REAL, discount_amount REAL, paid_amount REAL, created_date TEXT NOT NULL
);
CREATE TABLE outbound_messages (
    id TEXT PRIMARY KEY, email_record_id TEXT NOT NULL, sender_user TEXT, from_mailbox TEXT, to_addresses TEXT NOT NULL,
    cc_addresses TEXT, subject TEXT NOT NULL, body TEXT NOT NULL, threaded INTEGER DEFAULT 0, status TEXT DEFAULT 'sending',
    error TEXT, sent_at TEXT, created_date TEXT NOT NULL, updated_date TEXT NOT NULL
);
CREATE TABLE deleted_messages (source_message_id TEXT PRIMARY KEY, deleted_date TEXT NOT NULL);
CREATE TABLE option_lists (
    list_name TEXT NOT NULL, value TEXT NOT NULL, label TEXT NOT NULL, description TEXT, color TEXT, sort_order INTEGER DEFAULT 0,
    active INTEGER DEFAULT 1, is_system INTEGER DEFAULT 0, created_date TEXT NOT NULL, updated_date TEXT NOT NULL,
    PRIMARY KEY (list_name, value)
);
SQL;
}
