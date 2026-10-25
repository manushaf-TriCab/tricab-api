<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Service;

/**
 * @mentions in a task's notes (Nick Han, 23 Oct 2026: "Can @mention in the notes"). The notes are plain text and carry
 * each mention as a token, `@[Una User](user:12)`: the id is what counts (a rename never breaks it), the name is what
 * an older client shows. The people mentioned are read from the current notes, so nothing is stored beside them.
 */
final class TaskMentions
{
    private const TOKEN = '/@\[([^\[\]\r\n]{1,200})\]\(user:([1-9][0-9]{0,17})\)/u';

    /** @return list<int> the people the notes mention, each once, in order */
    public static function ids(string $notes): array
    {
        if (!str_contains($notes, '](user:') || preg_match_all(self::TOKEN, $notes, $found) === false) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $found[2])));
    }
}
