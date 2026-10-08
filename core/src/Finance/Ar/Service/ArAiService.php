<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Service;

use Closure;
use TriCab\Core\Finance\Ar\Domain\ArClassification;
use TriCab\Core\Finance\Ar\Domain\ArExtraction;
use TriCab\Core\Finance\Ar\Domain\ArInvalid;
use TriCab\Core\Finance\Ar\Domain\ArOption;
use TriCab\Core\Finance\Ar\Domain\ArProse;
use TriCab\Core\Finance\Ar\Domain\ArRecord;
use TriCab\Core\Finance\Ar\Domain\ArUpstream;
use TriCab\Core\Finance\Shared\Ai\FinanceAiClient;
use TriCab\Core\Finance\Shared\Ai\FinanceAiContract;
use TriCab\Core\Finance\Shared\Ai\FinanceAiFailure;

/**
 * Tri-AR's four model calls over `finance_ai` (Q10): classification (Jev through the decisions API), the prose
 * (summary, customer name and a draft reply), the reply composer's draft, and remittance extraction from a PDF or an
 * image. The prompts, models and thresholds are the service's (`finance_ai/purposes/ar.py`, the source's own); this
 * class sends **data only**, never a prompt (decision D10).
 *
 * Every failure is an {@see ArUpstream} (reason `ai`), logged with its purpose, code and correlation id and never
 * the data, so a down service, a bad key or a muddled answer is a typed, visible failure rather than an empty draft.
 */
final class ArAiService
{
    public const PURPOSE_CLASSIFY = 'ar_classify';
    public const PURPOSE_PROSE = 'ar_prose';
    public const PURPOSE_REPLY_DRAFT = 'ar_reply_draft';
    public const PURPOSE_REMITTANCE = 'ar_remittance';

    /** The source's 120 s for a document (`extract_remittance`). */
    public const REMITTANCE_DEADLINE_MS = 120_000;

    /** @var Closure(string): void */
    private readonly Closure $log;

    /**
     * @param (callable(string): void)|null $log written to `error_log` unless a test passes its own
     */
    public function __construct(
        private readonly FinanceAiClient $ai,
        private readonly ArOptionService $options,
        ?callable $log = null,
    ) {
        $this->log = Closure::fromCallable($log ?? static function (string $line): void {
            error_log($line);
        });
    }

    /**
     * The longest deadline any of the four purposes asks for, for sizing the transport.
     */
    public static function longestDeadlineMs(): int
    {
        return max(FinanceAiContract::DEFAULT_DEADLINE_MS, self::REMITTANCE_DEADLINE_MS);
    }

    public function classify(string $senderName, string $senderEmail, string $subject, string $body): ArClassification
    {
        $result = $this->ask(self::PURPOSE_CLASSIFY, [
            'sender_name' => $senderName,
            'sender_email' => $senderEmail,
            'subject' => $subject,
            'body' => $body,
            'categories' => self::optionData($this->options->activeOptions(ArOption::LIST_CATEGORY)),
            'methods' => self::optionData($this->options->activeOptions(ArOption::LIST_METHOD)),
        ]);

        return new ArClassification(
            self::string($result, 'follow_up_category'),
            self::string($result, 'proposed_response_type'),
            ($result['needs_review'] ?? null) === true,
            self::number($result, 'classification_confidence') ?? 0.0,
            self::string($result, 'follow_up_status'),
        );
    }

    public function prose(string $senderName, string $senderEmail, string $subject, string $body): ArProse
    {
        $result = $this->ask(self::PURPOSE_PROSE, [
            'sender_name' => $senderName,
            'sender_email' => $senderEmail,
            'subject' => $subject,
            'body' => $body,
        ]);

        return new ArProse(self::string($result, 'customer_name'), self::string($result, 'ai_summary'), self::string($result, 'proposed_response'));
    }

    /**
     * `POST /records/{id}/email-draft` (source route 19): a reply drafted or revised from the person's instruction.
     *
     * @return array{subject: string, body: string}
     */
    public function draftReply(ArRecord $record, string $instruction, string $previousDraft): array
    {
        if (trim($instruction) === '') {
            throw new ArInvalid('Tell the AI what the reply should say');
        }
        $result = $this->ask(self::PURPOSE_REPLY_DRAFT, [
            'customer_name' => $record->customerName,
            'sender_name' => $record->senderName,
            'sender_email' => $record->senderEmail,
            'subject' => $record->subject,
            'body' => $record->body ?? '',
            'instruction' => trim($instruction),
            'previous_draft' => $previousDraft,
        ]);

        return ['subject' => self::string($result, 'subject'), 'body' => self::string($result, 'body')];
    }

    /**
     * One document read for payment data. The bytes are sent base64, within the contract's file ceiling; the types the
     * service accepts are PDF and PNG, JPEG and TIFF images.
     */
    public function extractRemittance(string $subject, string $sender, string $fileName, string $contentType, string $bytes): ArExtraction
    {
        $result = $this->ask(self::PURPOSE_REMITTANCE, [
            'subject' => $subject,
            'sender' => $sender,
            'file' => ['name' => $fileName, 'content_type' => $contentType, 'data' => base64_encode($bytes)],
        ], self::REMITTANCE_DEADLINE_MS);

        $lines = [];
        $items = $result['line_items'] ?? null;
        foreach (is_array($items) ? $items : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $lines[] = [
                'invoice_number' => self::string($item, 'invoice_number'),
                'invoice_date' => self::stringOrNull($item, 'invoice_date'),
                'gross_amount' => self::number($item, 'gross_amount'),
                'discount_amount' => self::number($item, 'discount_amount'),
                'paid_amount' => self::number($item, 'paid_amount'),
            ];
        }

        return new ArExtraction(
            ($result['is_remittance'] ?? null) === true,
            self::string($result, 'payer_name'),
            self::stringOrNull($result, 'payment_date'),
            self::stringOrNull($result, 'payment_reference'),
            self::string($result, 'currency') !== '' ? self::string($result, 'currency') : 'AUD',
            self::number($result, 'total_amount'),
            $lines,
            self::number($result, 'extraction_confidence') ?? 0.0,
            self::string($result, 'notes'),
        );
    }

    // ---- helpers ------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     * @return array<array-key, mixed>
     */
    private function ask(string $purpose, array $data, ?int $deadlineMs = null): array
    {
        try {
            return $this->ai->complete($purpose, $data, $deadlineMs)->result;
        } catch (FinanceAiFailure $failure) {
            ($this->log)(sprintf(
                'Tri-AR AI call failed (purpose %s, code %s, correlation %s)',
                $purpose,
                $failure->failureCode,
                $failure->correlationId ?? '-',
            ));

            throw new ArUpstream($failure->getMessage(), ArUpstream::AI, $failure);
        }
    }

    /**
     * @param list<ArOption> $options
     * @return list<array{value: string, label: string, description: string}>
     */
    private static function optionData(array $options): array
    {
        return array_map(
            static fn (ArOption $o): array => ['value' => $o->value, 'label' => $o->label, 'description' => $o->description],
            $options,
        );
    }

    /**
     * @param array<array-key, mixed> $from
     */
    private static function string(array $from, string $key): string
    {
        $value = $from[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<array-key, mixed> $from
     */
    private static function stringOrNull(array $from, string $key): ?string
    {
        $value = $from[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $from
     */
    private static function number(array $from, string $key): ?float
    {
        $value = $from[$key] ?? null;

        return is_int($value) || is_float($value) ? (float) $value : null;
    }
}
