<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Application;

/**
 * Where one assistant turn spent its time.
 *
 * Shared by the service and the providers for the length of a request, then
 * written as a single `assistant_turn_timing` log line. It exists to answer
 * "why is the chat slow" with numbers: how many Foundry round trips a turn
 * made, how long each took, what the tools cost, and whether the answer came
 * from the agent or the deterministic fallback.
 *
 * Nothing the customer typed is recorded — only durations, counts, tool names
 * and token usage — because this lands in the App Service log stream.
 *
 * @phpstan-type ModelCall array{
 *     ms: int,
 *     toolsOffered: bool,
 *     outcome: string,
 *     stopReason: string|null,
 *     usage: array<string, int>,
 * }
 */
final class AssistantTurnTrace
{
    private int $startedAt = 0;

    /** @var array<string, int> */
    private array $phases = [];

    /** @var list<ModelCall> */
    private array $modelCalls = [];

    /** @var list<array{name: string, ms: int, ok: bool}> */
    private array $toolCalls = [];

    /** @var array<string, string|int|bool|null> */
    private array $notes = [];

    /** @var array{exception: string, message: string}|null */
    private ?array $fallback = null;

    public function begin(): void
    {
        $this->startedAt = hrtime(true);
        $this->phases = [];
        $this->modelCalls = [];
        $this->toolCalls = [];
        $this->notes = [];
        $this->fallback = null;
    }

    /**
     * Runs `$work` and adds its duration to `$phase`. Durations accumulate, so
     * a phase measured twice in one turn reports the total.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function measure(string $phase, callable $work): mixed
    {
        $started = hrtime(true);
        try {
            return $work();
        } finally {
            $this->phases[$phase] = ($this->phases[$phase] ?? 0) + self::msSince($started);
        }
    }

    /**
     * @param array<mixed> $usage Foundry's `usage` block, as returned.
     */
    public function recordModelCall(
        int $ms,
        bool $toolsOffered,
        string $outcome,
        ?string $stopReason = null,
        array $usage = [],
    ): void {
        /** @var array<string, int> $tokens */
        $tokens = [];
        foreach (['input_tokens', 'output_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens'] as $key) {
            if (is_int($usage[$key] ?? null)) {
                $tokens[$key] = $usage[$key];
            }
        }

        $this->modelCalls[] = [
            'ms' => $ms,
            'toolsOffered' => $toolsOffered,
            'outcome' => $outcome,
            'stopReason' => $stopReason,
            'usage' => $tokens,
        ];
    }

    public function recordToolCall(string $name, int $ms, bool $ok): void
    {
        $this->toolCalls[] = ['name' => $name, 'ms' => $ms, 'ok' => $ok];
    }

    public function recordFallback(\Throwable $exception): void
    {
        $this->fallback = [
            'exception' => $exception::class,
            'message' => mb_substr($exception->getMessage(), 0, 200),
        ];
    }

    public function note(string $key, string|int|bool|null $value): void
    {
        $this->notes[$key] = $value;
    }

    /**
     * @return array{
     *     event: string,
     *     totalMs: int|null,
     *     context: array<string, string|int|bool|null>,
     *     phasesMs: array<string, int>,
     *     modelCallCount: int,
     *     modelMs: int,
     *     modelCalls: list<ModelCall>,
     *     toolMs: int,
     *     toolCalls: list<array{name: string, ms: int, ok: bool}>,
     *     fellBack: bool,
     *     fallback: array{exception: string, message: string}|null,
     * }
     */
    public function summary(): array
    {
        return [
            'event' => 'assistant_turn_timing',
            'totalMs' => $this->startedAt === 0 ? null : self::msSince($this->startedAt),
            'context' => $this->notes,
            'phasesMs' => $this->phases,
            'modelCallCount' => count($this->modelCalls),
            'modelMs' => array_sum(array_column($this->modelCalls, 'ms')),
            'modelCalls' => $this->modelCalls,
            'toolMs' => array_sum(array_column($this->toolCalls, 'ms')),
            'toolCalls' => $this->toolCalls,
            'fellBack' => $this->fallback !== null,
            'fallback' => $this->fallback,
        ];
    }

    public function emit(): void
    {
        error_log(json_encode($this->summary(), JSON_UNESCAPED_SLASHES) ?: '{"event":"assistant_turn_timing"}');
    }

    public static function msSince(int $startedAtNs): int
    {
        return intdiv(hrtime(true) - $startedAtNs, 1_000_000);
    }
}
