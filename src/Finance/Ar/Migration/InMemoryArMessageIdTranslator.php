<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ar\Migration;

use TriCab\Core\Finance\Ar\Migration\ArMessageIdTranslationFailed;
use TriCab\Core\Finance\Ar\Migration\ArMessageIdTranslator;

/** A translator with a fixed table, for tests. An id not in the table has no translation (null); `$down` makes the whole service fail. */
final class InMemoryArMessageIdTranslator implements ArMessageIdTranslator
{
    /** @var list<array{mailbox: string, ids: list<string>}> */
    public array $calls = [];

    /** @param array<string, string> $table old id => immutable id */
    public function __construct(private readonly array $table = [], public bool $down = false)
    {
    }

    public function translate(string $mailbox, array $ids): array
    {
        $this->calls[] = ['mailbox' => $mailbox, 'ids' => $ids];
        if ($this->down) {
            throw new ArMessageIdTranslationFailed('The translation service is down.');
        }
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = $this->table[$id] ?? null;
        }

        return $out;
    }
}
