<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Infrastructure;

use TriCab\Core\Finance\Task\Contract\TaskDrafter;
use TriCab\Core\Finance\Task\Domain\TaskDraftRequest;

/**
 * A stand-in for the model, for local development and tests when Foundry is not set up (never bound in
 * production). One task per non-empty line (a leading bullet or number is dropped), the first member whose first
 * name appears in the line as its preparer, and a trailing "by <something>" as its due phrase. Dumb on purpose:
 * it exists so the whole flow can be driven without a model.
 *
 * A test may set `$next` to the exact raw proposal it wants back.
 */
final class HeuristicTaskDrafter implements TaskDrafter
{
    /** @var array<string, mixed>|null */
    public ?array $next = null;

    public ?TaskDraftRequest $lastRequest = null;

    public function draft(TaskDraftRequest $request): array
    {
        $this->lastRequest = $request;
        if ($this->next !== null) {
            return $this->next;
        }
        $tasks = [];
        foreach (preg_split('/\R+/', $request->text) ?: [] as $line) {
            $line = trim(preg_replace('/^\s*(?:[-*•]|\d+[.)])\s*/u', '', $line) ?? $line);
            if ($line === '') {
                continue;
            }
            $preparer = '';
            foreach ($request->members as $member) {
                $first = explode(' ', trim($member['name']))[0];
                if ($first !== '' && preg_match('/\b' . preg_quote($first, '/') . '\b/iu', $line) === 1) {
                    $preparer = $member['id'];
                    break;
                }
            }
            $due = preg_match('/\bby ([A-Za-z0-9 \/]+?)[.,;]?$/u', $line, $m) === 1 ? $m[1] : '';
            $tasks[] = ['name' => $line, 'preparer_id' => $preparer, 'due_phrase' => $due];
        }

        return ['tasks' => $tasks, 'injection_suspected' => false];
    }

    public function modelName(): string
    {
        return 'heuristic-local';
    }
}
