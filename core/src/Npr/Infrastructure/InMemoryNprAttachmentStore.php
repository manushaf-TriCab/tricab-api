<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Infrastructure;

use TriCab\Core\Npr\Contract\NprAttachmentStore;

/** Bytes in memory: the test double. */
final class InMemoryNprAttachmentStore implements NprAttachmentStore
{
    /** @var array<string, array{contentType: string, bytes: string}> */
    public array $objects = [];

    public function put(string $blobKey, string $contentType, string $bytes): void
    {
        $this->objects[$blobKey] = ['contentType' => $contentType, 'bytes' => $bytes];
    }

    public function get(string $blobKey): ?string
    {
        return $this->objects[$blobKey]['bytes'] ?? null;
    }

    public function discard(string $blobKey): void
    {
        unset($this->objects[$blobKey]);
    }
}
