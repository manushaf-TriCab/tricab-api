<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap;

use Closure;
use InvalidArgumentException;
use TriCab\Core\Finance\Shared\Ai\FinanceAiClient;
use TriCab\Core\Finance\Shared\Ai\FinanceAiFailure;

/**
 * Tri-AP's model calls over `finance_ai` (Q8): the five purposes of `finance_ai/purposes/ap.py`, whose prompts, model
 * (`google/gemini-2.5-flash`) and temperature (0) are the source's `server/extraction.py`. This class sends **data
 * only**, never a prompt (decision D10), and turns the answers into typed results.
 *
 * | Method | Purpose | Source |
 * |---|---|---|
 * | {@see self::classify()} | `ap_classify_extract` | `classify_and_extract_invoice`, once per attachment in a scan |
 * | {@see self::extract()} | `ap_extract` | `POST /api/extract` (the capture dialog); no caller-supplied prompt |
 * | {@see self::draftVendorEmail()} | `ap_vendor_email_draft` | `draft_vendor_email` |
 * | {@see self::draftReply()} | `ap_reply_draft` | `draft_email_reply` |
 * | {@see self::suggestReply()} | `ap_reply_suggest` | `suggest_email_reply`, once per scanned email, with an off switch |
 *
 * **When the provider fails.** The source raised `ExtractionError` for a missing key, a refusal, an unreachable
 * provider and an unreadable answer; here every such failure is a {@see FinanceAiFailure} (typed, with a message safe
 * to show), logged with its purpose, code and correlation id and never the data, and rethrown. The caller maps it as
 * the source did: `/extract` and both drafts answer 502 with the message; the scan counts the attachment as an error
 * ("Could not classify {name}; manual verification required") and goes on. The one exception is the suggestion, which
 * "returns an empty string rather than raising: a suggestion is a convenience, and a model failure there must never
 * fail the scan that captured the invoice".
 */
final class ApAiService
{
    public const PURPOSE_CLASSIFY_EXTRACT = 'ap_classify_extract';
    public const PURPOSE_EXTRACT = 'ap_extract';
    public const PURPOSE_VENDOR_EMAIL_DRAFT = 'ap_vendor_email_draft';
    public const PURPOSE_REPLY_DRAFT = 'ap_reply_draft';
    public const PURPOSE_REPLY_SUGGEST = 'ap_reply_suggest';

    /** The environment variable the source switched the suggestion off with (`AP_SUGGEST_REPLIES=0`), on by default. */
    public const SUGGEST_REPLIES_SETTING = 'AP_SUGGEST_REPLIES';

    /** The refusal of a vendor email draft with no instruction (`POST /invoices/{id}/email-draft`, 400). */
    public const VENDOR_INSTRUCTION_REQUIRED = 'Tell the AI what you need to ask the vendor';

    /** The refusal of a reply draft with no instruction (`POST /emails/{id}/email-draft`, 400). */
    public const REPLY_INSTRUCTION_REQUIRED = 'Tell the AI what the reply should say';

    /** @var Closure(string): void */
    private readonly Closure $log;

    /**
     * @param bool $suggestRepliesEnabled the {@see self::SUGGEST_REPLIES_SETTING} setting, see {@see self::suggestRepliesEnabled()}
     * @param float $confidenceThreshold `INVOICE_CONFIDENCE_THRESHOLD`: below it a capture is flagged for review (0.80)
     * @param (callable(string): void)|null $log written to `error_log` unless a test passes its own
     */
    public function __construct(
        private readonly FinanceAiClient $ai,
        private readonly bool $suggestRepliesEnabled = true,
        private readonly float $confidenceThreshold = ApExtraction::CONFIDENCE_THRESHOLD,
        ?callable $log = null,
    ) {
        $this->log = Closure::fromCallable($log ?? static function (string $line): void {
            error_log($line);
        });
    }

    /**
     * `_suggest_replies_enabled()`: on unless the setting is `0`, `false` or `no` (any case, trimmed). Unset is on.
     */
    public static function suggestRepliesEnabled(?string $setting): bool
    {
        return !in_array(strtolower(trim($setting ?? '1')), ['0', 'false', 'no'], true);
    }

    /**
     * One call per attachment during a scan. The bytes are sent base64 within the contract's file ceiling.
     *
     * @param string $subject the email's subject (the service uses its first 500 characters)
     * @param string $sender the email's sender (the first 300)
     * @param string $contentType application/pdf, image/png, image/jpeg or image/tiff
     * @param list<ApExtractionCompany> $companies every row of the companies list; the active ones are named in the prompt
     *
     * @throws FinanceAiFailure
     */
    public function classify(string $subject, string $sender, string $fileName, string $contentType, string $bytes, array $companies): ApClassification
    {
        $result = $this->ask(self::PURPOSE_CLASSIFY_EXTRACT, [
            'subject' => $subject,
            'sender' => $sender,
            'companies' => ApExtractionCompany::promptList($companies),
            'file' => self::file($fileName, $contentType, $bytes),
        ]);

        return ApClassification::fromResult($result, $companies, $this->confidenceThreshold);
    }

    /**
     * Manual extraction of an uploaded file (`POST /extract`). The browser sends no prompt and the service uses the
     * source's own. `toArray()` of the answer is what the capture dialog reads.
     *
     * @param list<ApExtractionCompany> $companies
     *
     * @throws FinanceAiFailure
     */
    public function extract(string $fileName, string $contentType, string $bytes, array $companies): ApExtraction
    {
        $result = $this->ask(self::PURPOSE_EXTRACT, [
            'companies' => ApExtractionCompany::promptList($companies),
            'file' => self::file($fileName, $contentType, $bytes),
        ]);

        return ApExtraction::fromResult($result, $companies, $this->confidenceThreshold);
    }

    /**
     * `POST /invoices/{id}/email-draft`: a question for the vendor, drafted or revised from the person's instruction.
     *
     * @throws InvalidArgumentException with {@see self::VENDOR_INSTRUCTION_REQUIRED} when the instruction is empty
     * @throws FinanceAiFailure
     */
    public function draftVendorEmail(ApDraftInvoice $invoice, string $instruction, string $previousDraft = ''): ApDraft
    {
        $instruction = trim($instruction);
        if ($instruction === '') {
            throw new InvalidArgumentException(self::VENDOR_INSTRUCTION_REQUIRED);
        }
        $result = $this->ask(self::PURPOSE_VENDOR_EMAIL_DRAFT, [
            'invoice' => $invoice->promptData(),
            'instruction' => $instruction,
            'previous_draft' => $previousDraft,
        ]);

        return new ApDraft($invoice->to(), self::string($result, 'subject'), self::string($result, 'body'));
    }

    /**
     * `POST /emails/{id}/email-draft`: a reply to a scanned email, drafted or revised from the person's instruction.
     *
     * @throws InvalidArgumentException with {@see self::REPLY_INSTRUCTION_REQUIRED} when the instruction is empty
     * @throws FinanceAiFailure
     */
    public function draftReply(ApDraftEmail $email, string $instruction, string $previousDraft = ''): ApDraft
    {
        $instruction = trim($instruction);
        if ($instruction === '') {
            throw new InvalidArgumentException(self::REPLY_INSTRUCTION_REQUIRED);
        }
        $result = $this->ask(self::PURPOSE_REPLY_DRAFT, [
            'email' => $email->promptData(),
            'instruction' => $instruction,
            'previous_draft' => $previousDraft,
        ]);

        return new ApDraft($email->to(), self::string($result, 'subject'), self::string($result, 'body'));
    }

    /**
     * The unprompted first-draft reply made once per scanned email.
     *
     * Returns null when the suggestion is switched off (store nothing, as the source did not call the model), and
     * otherwise the body: empty when the email needs no reply or the model failed (the failure is logged, never thrown).
     * Not overwriting a suggestion already stored for the email is the scan's rule, not this method's.
     */
    public function suggestReply(ApDraftEmail $email): ?string
    {
        if (!$this->suggestRepliesEnabled) {
            return null;
        }
        try {
            return trim(self::string($this->ask(self::PURPOSE_REPLY_SUGGEST, ['email' => $email->promptData()]), 'body'));
        } catch (FinanceAiFailure) {
            return '';
        }
    }

    // ---- helpers ------------------------------------------------------------------------

    /**
     * @return array{name: string, content_type: string, data: string}
     */
    private static function file(string $fileName, string $contentType, string $bytes): array
    {
        return ['name' => $fileName, 'content_type' => $contentType, 'data' => base64_encode($bytes)];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<array-key, mixed>
     *
     * @throws FinanceAiFailure
     */
    private function ask(string $purpose, array $data): array
    {
        try {
            return $this->ai->complete($purpose, $data)->result;
        } catch (FinanceAiFailure $failure) {
            ($this->log)(sprintf(
                'Tri-AP AI call failed (purpose %s, code %s, correlation %s)',
                $purpose,
                $failure->failureCode,
                $failure->correlationId ?? '-',
            ));

            throw $failure;
        }
    }

    /**
     * @param array<array-key, mixed> $from
     */
    private static function string(array $from, string $key): string
    {
        $value = $from[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
