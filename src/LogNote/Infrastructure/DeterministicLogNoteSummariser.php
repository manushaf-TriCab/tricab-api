<?php

declare(strict_types=1);

namespace TriCab\Api\LogNote\Infrastructure;

use TriCab\Core\LogNote\Contract\LogNoteSummariser;
use TriCab\Core\LogNote\Domain\ValueObject\LogNoteBody;

final class DeterministicLogNoteSummariser implements LogNoteSummariser
{
    public function summarise(string $rawText): string
    {
        $collapsed = trim(preg_replace('/\s+/u', ' ', $rawText) ?? $rawText);
        if ($collapsed === '') {
            return $collapsed;
        }

        $sentences = preg_split('/(?<=[.!?])\s+/u', $collapsed, -1, PREG_SPLIT_NO_EMPTY) ?: [$collapsed];
        $selected = array_slice($sentences, 0, 3);
        $summary = implode(' ', $selected);
        if (mb_strlen($summary) > 1200) {
            $summary = rtrim(mb_substr($summary, 0, 1200));
        }

        $prefixed = 'Summary: ' . $summary;
        if (mb_strlen($prefixed) > LogNoteBody::MAX_LENGTH) {
            return rtrim(mb_substr($prefixed, 0, LogNoteBody::MAX_LENGTH));
        }

        return $prefixed;
    }

    public function providerName(): string
    {
        return 'deterministic';
    }
}
