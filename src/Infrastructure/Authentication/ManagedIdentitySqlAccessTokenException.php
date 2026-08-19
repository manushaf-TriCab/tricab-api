<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Authentication;

use RuntimeException;

/**
 * Failed to acquire an Azure SQL access token via managed identity.
 * Messages must never include tokens, identity headers, or credential payloads.
 */
final class ManagedIdentitySqlAccessTokenException extends RuntimeException
{
}
