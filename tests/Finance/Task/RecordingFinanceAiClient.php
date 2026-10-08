<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use TriCab\Core\Finance\Shared\Ai\FinanceAiClient;
use TriCab\Core\Finance\Shared\Ai\FinanceAiFailure;
use TriCab\Core\Finance\Shared\Ai\FinanceAiResult;

/**
 * A finance AI client that never leaves the process: it remembers what it was asked and answers
 * from a queue (or a default per purpose). Nothing here calls a model.
 */
final class RecordingFinanceAiClient implements FinanceAiClient
{
    /** @var list<array{purpose: string, data: array<string, mixed>, deadlineMs: ?int}> */
    public array $calls = [];

    /** @var list<array<array-key, mixed>|FinanceAiFailure> answers used in order before the defaults */
    public array $queue = [];

    public function complete(string $purpose, array $data, ?int $deadlineMs = null): FinanceAiResult
    {
        $this->calls[] = ['purpose' => $purpose, 'data' => $data, 'deadlineMs' => $deadlineMs];
        $answer = $this->queue === [] ? self::defaultFor($purpose) : array_shift($this->queue);
        if ($answer instanceof FinanceAiFailure) {
            throw $answer;
        }

        return new FinanceAiResult($purpose, $answer, 'corr-0123456789abcdef', 'openrouter', 'test/model');
    }

    public function isAlive(): bool
    {
        return true;
    }

    /** @return array<array-key, mixed> */
    private static function defaultFor(string $purpose): array
    {
        return match ($purpose) {
            'task_details' => ['description' => 'Reconcile the bank.', 'requirements' => '- Sign off'],
            'statement_areas' => ['items' => [['name' => 'Revenue', 'category' => 'income_statement', 'description' => 'd']]],
            default => ['subject' => 'Hello', 'body' => 'Please do it.'],
        };
    }
}
