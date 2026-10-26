<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use TriCab\Core\Finance\Ap\Service\ApTime;
use TriCab\Core\Finance\Ap2\Contract\Ap2AiUsageReport;
use TriCab\Core\Finance\Ap2\Contract\Ap2InvoiceStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2Failure;
use TriCab\Core\Finance\Ap2\Domain\Ap2Invalid;

/**
 * `GET /ai-usage`: what the AI used and cost over a period of calendar days (Sydney, both ends included; the last 30
 * days by default), and what that comes to per invoice created in the same days.
 */
final class Ap2AiUsageService
{
    private const DEFAULT_DAYS = 30;
    private const MAX_DAYS = 366;

    public function __construct(
        private readonly Ap2AiUsageReport $report,
        private readonly Ap2InvoiceStore $invoices,
        private readonly ApTime $time,
    ) {
    }

    /**
     * @param array<string, mixed> $query `from` and `to` as yyyy-mm-dd
     * @return array<string, mixed>
     */
    public function usage(array $query): array
    {
        $issues = [];
        $today = $this->time->today();
        $to = $this->day($query, 'to', $today, $issues) ?? $today;
        $from = $this->day($query, 'from', null, $issues);
        if ($issues === []) {
            $from ??= (new DateTimeImmutable($to))->sub(new DateInterval('P' . (self::DEFAULT_DAYS - 1) . 'D'))->format('Y-m-d');
            $span = (int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->format('%r%a');
            if ($span < 0) {
                $issues[] = 'from must not be after to';
            } elseif ($span + 1 > self::MAX_DAYS) {
                $issues[] = 'the period must not be longer than ' . self::MAX_DAYS . ' days';
            }
        }
        if ($issues !== [] || $from === null) {
            throw new Ap2Invalid('The period is invalid', Ap2Failure::details($issues));
        }

        [$start, $end] = $this->time->dayBounds($from, $to);
        if ($start === null || $end === null) {
            throw new Ap2Invalid('The period is invalid', Ap2Failure::details(['from and to must be dates']));
        }

        $rows = [];
        $byPurpose = [];
        $calls = $failed = $input = $output = 0;
        $cost = 0.0;
        foreach ($this->report->totals($start, $end) as $t) {
            $rows[] = [
                'purpose' => $t->purpose,
                'model' => $t->model,
                'calls' => $t->calls,
                'failed' => $t->failed,
                'inputTokens' => $t->inputTokens,
                'outputTokens' => $t->outputTokens,
                'cacheReadTokens' => $t->cacheReadTokens,
                'cacheWriteTokens' => $t->cacheWriteTokens,
                'costUsd' => round($t->costUsd, 6),
                'durationMs' => $t->durationMs,
            ];
            $calls += $t->calls;
            $failed += $t->failed;
            $input += $t->inputTokens;
            $output += $t->outputTokens;
            $cost += $t->costUsd;
            $prior = $byPurpose[$t->purpose] ?? ['calls' => 0, 'costUsd' => 0.0];
            $byPurpose[$t->purpose] = ['calls' => $prior['calls'] + $t->calls, 'costUsd' => round($prior['costUsd'] + $t->costUsd, 6)];
        }
        $cost = round($cost, 6);
        $invoices = $this->invoices->countCreated($start, $end);

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'totals' => ['calls' => $calls, 'failed' => $failed, 'inputTokens' => $input, 'outputTokens' => $output, 'costUsd' => $cost],
            'byPurpose' => (object) $byPurpose,
            'invoices' => $invoices,
            'costPerInvoiceUsd' => $invoices === 0 ? null : round($cost / $invoices, 6),
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @param list<string> $issues
     */
    private function day(array $query, string $key, ?string $default, array &$issues): ?string
    {
        $value = $query[$key] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }
        $parsed = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC')) : false;
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            $issues[] = $key . ' must be a date as yyyy-mm-dd';

            return null;
        }

        return $value;
    }
}
