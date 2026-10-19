<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Domain\Service;

use TriCab\Core\Mail\Application\DTO\ThreadSummary;

/**
 * Which queue a thread is in, and how a list is ordered. The in-memory store uses this directly; the
 * Azure SQL store states the same rules in SQL, and the shared tests run the same cases against both.
 */
final class ThreadQueues
{
    public static function contains(ThreadSummary $thread, string $queue): bool
    {
        return match ($queue) {
            'all' => true,
            'marked' => $thread->decided,
            'skipped' => $thread->skipped,
            // A skipped thread is in Skipped (and Marked, once decided) and in no label queue.
            default => !$thread->skipped && $thread->effectiveLabel === $queue,
        };
    }

    /** @return callable(ThreadSummary, ThreadSummary): int */
    public static function comparator(string $sort): callable
    {
        $newest = static fn (ThreadSummary $a, ThreadSummary $b): int => ($b->latestAt <=> $a->latestAt) ?: strcmp($a->id, $b->id);

        return match ($sort) {
            'oldest' => static fn (ThreadSummary $a, ThreadSummary $b): int => ($a->latestAt <=> $b->latestAt) ?: strcmp($a->id, $b->id),
            'sender' => static fn (ThreadSummary $a, ThreadSummary $b): int => strcasecmp($a->from->name, $b->from->name) ?: $newest($a, $b),
            'confidence' => static fn (ThreadSummary $a, ThreadSummary $b): int => ($b->confidence <=> $a->confidence) ?: $newest($a, $b),
            default => $newest,
        };
    }

    /** Subject, sender name and address, preview: the same four fields the screen's search box describes. */
    public static function matches(ThreadSummary $thread, string $search): bool
    {
        if ($search === '') {
            return true;
        }
        foreach ([$thread->subject, $thread->from->name, $thread->from->address, $thread->preview] as $field) {
            if (mb_stripos($field, $search) !== false) {
                return true;
            }
        }

        return false;
    }
}
