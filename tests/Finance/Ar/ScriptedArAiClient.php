<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar;

use TriCab\Core\Finance\Shared\Ai\FinanceAiClient;
use TriCab\Core\Finance\Shared\Ai\FinanceAiFailure;
use TriCab\Core\Finance\Shared\Ai\FinanceAiResult;

/**
 * A finance AI client for the Tri-AR tests that never leaves the process: it answers by purpose, from `$answers`
 * (an answer, or a failure to throw), and remembers every call. Nothing
 * here calls a model.
 */
final class ScriptedArAiClient implements FinanceAiClient
{
    /** @var list<array{purpose: string, data: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, array<array-key, mixed>|FinanceAiFailure> */
    public array $answers = [];

    public function complete(string $purpose, array $data, ?int $deadlineMs = null): FinanceAiResult
    {
        $this->calls[] = ['purpose' => $purpose, 'data' => $data];
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

    /** @return array<array-key, mixed> */
    private static function defaultFor(string $purpose): array
    {
        return match ($purpose) {
            'ar_classify' => [
                'follow_up_category' => 'release_customer_orders', 'proposed_response_type' => 'email', 'needs_review' => false,
                'classification_confidence' => 0.91, 'follow_up_status' => 'in_progress',
            ],
            'ar_prose' => ['customer_name' => 'Acme Marine Pty Ltd', 'ai_summary' => 'A short summary.', 'proposed_response' => 'Thanks, done.'],
            'ar_remittance' => [
                'is_remittance' => true, 'payer_name' => 'Acme Marine', 'payment_date' => '2026-10-01', 'payment_reference' => 'EFT-1', 'currency' => 'AUD',
                'total_amount' => 30.0, 'line_items' => [
                    ['invoice_number' => 'INV-1', 'invoice_date' => '2026-09-01', 'gross_amount' => 10.0, 'discount_amount' => null, 'paid_amount' => 10.0],
                    ['invoice_number' => 'INV-2', 'invoice_date' => null, 'gross_amount' => 20.0, 'discount_amount' => null, 'paid_amount' => 20.0],
                ], 'extraction_confidence' => 0.9, 'notes' => '',
            ],
            default => [],
        };
    }
}
