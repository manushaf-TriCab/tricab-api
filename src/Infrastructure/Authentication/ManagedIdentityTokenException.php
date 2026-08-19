<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Authentication;

use RuntimeException;

/**
 * Managed-identity HTTP/token acquisition failed.
 * Messages must never include tokens, identity headers, or credential payloads.
 */
final class ManagedIdentityTokenException extends RuntimeException
{
}
