<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO;

use TriCab\Core\Rfq\Parser\QuoteReadiness;

/**
 * Readiness of the lines as they stand in the editor, saved or not (parity
 * plan E3.5). Contract `rfq.readiness.v1`.
 *
 * The source's `quote_readiness`, asked of the rows a save would write. Each
 * line gets the source's sales `severity` (`fix_required`, `check`, `ready`,
 * `excluded`) and its `codes`, each with the `display` that decides whether
 * it shows inline (`fix_required`, `check`) or folded (`note`). Lines are
 * named by `lineUid`; the source names rows by position, and that is how
 * they are matched here.
 */
final class RfqReadinessView
{
    /**
     * @param list<array{lineUid: ?string, reason: string, field: ?string}> $blockingIssues
     * @param list<array{lineUid: string, severity: string, codes: list<array{code: string, display: string, detail: string}>}> $lines
     */
    private function __construct(
        public readonly bool $ready,
        public readonly array $blockingIssues,
        public readonly array $lines,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $rows the rows readiness was asked about, in order
     */
    public static function from(QuoteReadiness $readiness, array $rows): self
    {
        $uids = array_map(static fn (array $row): string => is_string($row['line_uid'] ?? null) ? $row['line_uid'] : '', $rows);

        $issues = [];
        foreach ($readiness->blockingIssues as $issue) {
            $number = $issue['row_number'] ?? null;
            $issues[] = [
                'lineUid' => is_int($number) ? ($uids[$number - 1] ?? null) : null,
                'reason' => is_string($issue['reason'] ?? null) ? $issue['reason'] : 'This quote cannot be synced yet.',
                'field' => is_string($issue['field'] ?? null) ? $issue['field'] : null,
            ];
        }

        $lines = [];
        foreach ($readiness->rowStates as $index => $state) {
            $number = $state['row_number'] ?? null;
            $uid = is_int($number) ? ($uids[$number - 1] ?? null) : ($uids[$index] ?? null);
            if ($uid === null || $uid === '') {
                continue;
            }
            $codes = [];
            foreach (is_array($state['codes'] ?? null) ? $state['codes'] : [] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $codes[] = [
                    'code' => is_string($entry['code'] ?? null) ? $entry['code'] : '',
                    'display' => in_array($entry['display'] ?? null, ['fix_required', 'check', 'note'], true) ? $entry['display'] : 'note',
                    'detail' => is_string($entry['detail'] ?? null) ? $entry['detail'] : '',
                ];
            }
            $severity = $state['severity'] ?? null;
            $lines[] = [
                'lineUid' => $uid,
                'severity' => in_array($severity, ['fix_required', 'check', 'ready', 'excluded'], true) ? $severity : 'fix_required',
                'codes' => $codes,
            ];
        }

        return new self($readiness->ready, $issues, $lines);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['ready' => $this->ready, 'blockingIssues' => $this->blockingIssues, 'lines' => $this->lines];
    }
}
