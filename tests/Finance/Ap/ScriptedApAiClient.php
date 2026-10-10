<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use TriCab\Core\Finance\Shared\Ai\FinanceAiClient;
use TriCab\Core\Finance\Shared\Ai\FinanceAiFailure;
use TriCab\Core\Finance\Shared\Ai\FinanceAiResult;

/**
 * A finance AI client for the Tri-AP tests that never leaves the process: it answers by purpose, from `$answers` (an
 * answer, or a failure to throw), and remembers every call. Nothing here calls a model.
 */
final class ScriptedApAiClient implements FinanceAiClient
{
    /** @var list<array{purpose: string, data: array<string, mixed>, deadlineMs: ?int}> */
    public array $calls = [];

    /** @var array<string, array<array-key, mixed>|FinanceAiFailure> */
    public array $answers = [];

    public function complete(string $purpose, array $data, ?int $deadlineMs = null): FinanceAiResult
    {
        $this->calls[] = ['purpose' => $purpose, 'data' => $data, 'deadlineMs' => $deadlineMs];
        $answer = $this->answers[$purpose] ?? self::defaultFor($purpose);
        if ($answer instanceof FinanceAiFailure) {
            throw $answer;
        }

        return new FinanceAiResult($purpose, $answer, 'corr-0123456789abcdef', 'openrouter', 'test/model');
    }

    public function isAlive(): bool
    {
        return true;
    }

    /** How many calls a purpose has had. */
    public function count(string $purpose): int
    {
        return count(array_filter($this->calls, static fn (array $call): bool => $call['purpose'] === $purpose));
    }

    /**
     * The invoice fields the service returns for a clean, confident read.
     *
     * @return array<string, mixed>
     */
    public static function invoiceFields(): array
    {
        return [
            'invoice_no' => 'INV-42', 'vendor' => 'Acme Pty Ltd', 'company' => 'TriCab (Australia) Pty Ltd',
            'vendor_email' => 'ap@acme.example.test', 'tax_id' => '12 345 678 901', 'invoice_date' => '2026-09-01',
            'due_date' => '2026-09-30', 'po_number' => 'PO-7', 'currency' => 'AUD', 'net_amount' => 100, 'tax_amount' => 10,
            'total_amount' => 110, 'extraction_confidence' => 0.93, 'notes' => 'Fine.',
        ];
    }

    /** @return array<array-key, mixed> */
    private static function defaultFor(string $purpose): array
    {
        return match ($purpose) {
            'ap_classify_extract' => [
                ...self::invoiceFields(), 'extraction_confidence' => null, 'is_invoice' => true,
                'classification_reason' => 'Tax invoice for cable.', 'classification_confidence' => 0.91,
            ],
            'ap_extract' => self::invoiceFields(),
            'ap_vendor_email_draft', 'ap_reply_draft' => ['subject' => 'Question about INV-42', 'body' => 'When will this be paid?'],
            'ap_reply_suggest' => ['body' => 'Thanks, we will check.'],
            default => [],
        };
    }
}
