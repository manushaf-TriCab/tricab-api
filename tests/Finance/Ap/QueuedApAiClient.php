<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use TriCab\Core\Finance\Shared\Ai\FinanceAiClient;
use TriCab\Core\Finance\Shared\Ai\FinanceAiFailure;
use TriCab\Core\Finance\Shared\Ai\FinanceAiResult;

/**
 * A finance AI client for the Tri-AP scan tests: each purpose answers from a queue (an answer, or a failure to throw), one per
 * call, and falls back to the fixed answers {@see ScriptedApAiClient} gives when its queue is empty. Every call is remembered.
 * Nothing here calls a model.
 */
final class QueuedApAiClient implements FinanceAiClient
{
    /** @var list<array{purpose: string, data: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, list<array<array-key, mixed>|FinanceAiFailure>> */
    private array $queues = [];

    private readonly ScriptedApAiClient $fallback;

    public function __construct()
    {
        $this->fallback = new ScriptedApAiClient();
    }

    /**
     * @param array<array-key, mixed>|FinanceAiFailure $answer
     */
    public function queue(string $purpose, array|FinanceAiFailure $answer): void
    {
        $this->queues[$purpose][] = $answer;
    }

    /**
     * Queues a classify-and-extract answer for an invoice: the clean fields with any of them replaced.
     *
     * @param array<string, mixed> $over
     */
    public function invoice(array $over = []): void
    {
        $this->queue('ap_classify_extract', array_merge(self::invoiceAnswer(), $over));
    }

    /** Queues a classify-and-extract answer for a file that is not an invoice. */
    public function notInvoice(?string $reason = 'A remittance advice, not an invoice.'): void
    {
        $this->queue('ap_classify_extract', array_merge(self::invoiceAnswer(), ['is_invoice' => false, 'classification_reason' => $reason]));
    }

    public function complete(string $purpose, array $data, ?int $deadlineMs = null): FinanceAiResult
    {
        $this->calls[] = ['purpose' => $purpose, 'data' => $data];
        $queued = $this->queues[$purpose] ?? [];
        $answer = array_shift($queued);
        $this->queues[$purpose] = $queued;
        if ($answer === null) {
            $answer = $purpose === 'ap_classify_extract' ? self::invoiceAnswer() : $this->fallback->complete($purpose, $data)->result;
        }
        if ($answer instanceof FinanceAiFailure) {
            throw $answer;
        }

        return new FinanceAiResult($purpose, $answer, 'corr-0123456789abcdef', 'openrouter', 'test/model');
    }

    public function isAlive(): bool
    {
        return true;
    }

    public function count(string $purpose): int
    {
        return count(array_filter($this->calls, static fn (array $call): bool => $call['purpose'] === $purpose));
    }

    /**
     * @return array<string, mixed>
     */
    private static function invoiceAnswer(): array
    {
        return array_merge(ScriptedApAiClient::invoiceFields(), [
            'is_invoice' => true,
            'classification_reason' => 'Tax invoice for cable.',
            'classification_confidence' => 0.91,
        ]);
    }
}
