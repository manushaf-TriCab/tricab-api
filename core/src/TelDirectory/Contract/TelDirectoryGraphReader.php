<?php

declare(strict_types=1);

namespace TriCab\Core\TelDirectory\Contract;

/**
 * Read-only Microsoft Graph directory feed for Tel Directory.
 * Implementations must never write to Graph or Azure SQL directory tables.
 *
 * @return list<array<string, mixed>> Raw Graph user rows (selected fields only).
 */
interface TelDirectoryGraphReader
{
    public function isConfigured(): bool;

    /**
     * @return list<array<string, mixed>>
     */
    public function listActiveDirectoryUsers(): array;
}
