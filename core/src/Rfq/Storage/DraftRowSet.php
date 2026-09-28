<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Storage;

/**
 * Replaces a draft's lines: the source store's `_replace_rows`, ported exactly.
 *
 * Pure. It is given the lines as stored and the lines as saved, and returns the
 * `rfq_draft_rows` and `rfq_draft_row_edits` values to write. The store runs
 * it inside the transaction that locked the draft.
 *
 *  - **Lines are matched to their previous selves by `line_uid`**, which lives
 *    in `row_data` so that the browser round-trips it. Positional matching
 *    moved every later line's original-parse snapshot onto its neighbour when
 *    a line was inserted, falsifying the accuracy data. The one exception is a
 *    line saved before `line_uid` existed: a uid-less stored line may still be
 *    paired by position, once.
 *  - **The system snapshot is the parser's answer**, taken when a line first
 *    appears and carried unchanged after. A line with no previous self (added
 *    by hand) gets a snapshot of itself marked invalid, so it never counts as
 *    the parser's.
 *  - **Edits:** one per tracked field that changed, with `from_system` 1 when
 *    the old value was still the parser's; one `line` edit per line added or
 *    removed. Removing a parser line counts as overriding the parser.
 *
 * Ids and times come from `$ids` and `$now` in the order the source draws
 * them, so the parity fixture can check the output byte for byte.
 */
final class DraftRowSet
{
    /**
     * @param list<array{line_uid: ?string, row_data: ?string, system_row_data: ?string, system_snapshot_valid: int|string|bool|null}> $previous
     *                                                                                                                                  the stored lines, in row order
     * @param list<array<string, mixed>> $rows                                                                                          the lines as saved
     * @param callable(): string          $ids                                                                                          a new 32-hex id
     * @param callable(): string          $now                                                                                          the source's `_now_iso()`
     *
     * @return array{
     *     rows: list<array{id: string, draft_id: string, row_order: int, row_data: string, system_row_data: ?string, system_snapshot_valid: int, line_uid: string}>,
     *     edits: list<array{id: string, draft_id: string, line_uid: string, row_order: int, revision: ?int, created_at: string, actor_email: ?string, actor_role: ?string, view_mode: ?string, field: string, old_value: string, new_value: string, from_system: int}>
     * }
     */
    public static function replace(
        string $draftId,
        array $previous,
        array $rows,
        bool $initial,
        ?DraftActor $actor,
        ?int $revision,
        string $viewMode,
        callable $ids,
        callable $now,
    ): array {
        if ($initial) {
            $previous = [];
        }
        /** @var array<string, int> $byUidIndex */
        $byUidIndex = [];
        foreach ($previous as $index => $prev) {
            if (DraftChangeLog::truthy($prev['line_uid'])) {
                $byUidIndex[(string) $prev['line_uid']] = $index;
            }
        }
        $matched = [];
        $values = [];
        $edits = [];

        foreach ($rows as $order => $row) {
            unset($row['_draft_row_id'], $row['_system_snapshot_valid']);
            $row = DraftChangeLog::normaliseProductCodes($row);
            $uid = PythonText::str(DraftChangeLog::truthy($row['line_uid'] ?? null) ? $row['line_uid'] : '');
            $lineUid = $uid !== '' ? $uid : $ids();
            $row['line_uid'] = $lineUid;

            $prior = null;
            $priorIndex = null;
            if (!$initial) {
                $priorIndex = $byUidIndex[$lineUid] ?? null;
                if ($priorIndex !== null) {
                    $prior = $previous[$priorIndex];
                } elseif ($order < count($previous) && !DraftChangeLog::truthy($previous[$order]['line_uid'])) {
                    $priorIndex = $order;
                    $prior = $previous[$order];
                }
            }
            if ($priorIndex !== null) {
                $matched[$priorIndex] = true;
            }

            if ($initial || $prior === null) {
                $systemData = PythonJson::encode($row);
                $valid = $initial ? 1 : 0;
            } else {
                $systemData = $prior['system_row_data'];
                $valid = (int) $prior['system_snapshot_valid'];
            }

            if ($prior !== null) {
                $before = DraftChangeLog::trackedValues(PythonJson::decodeObject($prior['row_data']));
                $after = DraftChangeLog::trackedValues($row);
                $changed = array_values(array_filter(
                    DraftChangeLog::TRACKED_ROW_FIELDS,
                    static fn (string $field): bool => $before[$field] !== $after[$field],
                ));
                if ($changed !== []) {
                    $original = DraftChangeLog::trackedValues(PythonJson::decodeObject($systemData));
                    $createdAt = $now();
                    foreach ($changed as $field) {
                        $edits[] = self::edit($ids(), $draftId, $lineUid, $order, $revision, $createdAt, $actor, $viewMode,
                            $field, $before[$field], $after[$field], $before[$field] === $original[$field]);
                    }
                }
            } elseif (!$initial) {
                $edits[] = self::edit($ids(), $draftId, $lineUid, $order, $revision, $now(), $actor, $viewMode,
                    DraftChangeLog::LINE_PRESENCE_FIELD, '', DraftChangeLog::lineSummary($row), false);
            }

            $values[] = [
                'id' => $ids(),
                'draft_id' => $draftId,
                'row_order' => $order,
                'row_data' => PythonJson::encode($row),
                'system_row_data' => $systemData,
                'system_snapshot_valid' => $valid,
                'line_uid' => $lineUid,
            ];
        }

        foreach ($previous as $index => $prev) {
            if (isset($matched[$index])) {
                continue;
            }
            $edits[] = self::edit($ids(), $draftId, (string) ($prev['line_uid'] ?? ''), $index, $revision, $now(), $actor, $viewMode,
                DraftChangeLog::LINE_PRESENCE_FIELD, DraftChangeLog::lineSummary(PythonJson::decodeObject($prev['row_data'])), '',
                DraftChangeLog::truthy((int) $prev['system_snapshot_valid']));
        }

        return ['rows' => $values, 'edits' => $edits];
    }

    /**
     * @return array{id: string, draft_id: string, line_uid: string, row_order: int, revision: ?int, created_at: string, actor_email: ?string, actor_role: ?string, view_mode: ?string, field: string, old_value: string, new_value: string, from_system: int}
     */
    private static function edit(
        string $id,
        string $draftId,
        string $lineUid,
        int $order,
        ?int $revision,
        string $createdAt,
        ?DraftActor $actor,
        string $viewMode,
        string $field,
        string $old,
        string $new,
        bool $fromSystem,
    ): array {
        return [
            'id' => $id,
            'draft_id' => $draftId,
            'line_uid' => $lineUid,
            'row_order' => $order,
            'revision' => $revision,
            'created_at' => $createdAt,
            'actor_email' => $actor?->email,
            'actor_role' => $actor?->role,
            'view_mode' => $viewMode !== '' ? $viewMode : null,
            'field' => $field,
            'old_value' => $old,
            'new_value' => $new,
            'from_system' => $fromSystem ? 1 : 0,
        ];
    }
}
