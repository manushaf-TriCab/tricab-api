<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Groups;

/** GRAPH_TENANT_ID / GRAPH_CLIENT_ID / GRAPH_CLIENT_SECRET are not all set. */
final class GraphNotConfigured extends EntraGroupDirectoryUnavailable
{
    public const OUTCOME = 'graph_not_configured';

    public static function make(): self
    {
        return new self('Microsoft Graph is not configured (GRAPH_TENANT_ID, GRAPH_CLIENT_ID, GRAPH_CLIENT_SECRET).');
    }

    public function outcome(): string
    {
        return self::OUTCOME;
    }
}
