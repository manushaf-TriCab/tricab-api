<?php

declare(strict_types=1);

namespace TriCab\Core\Conductor\Process\Application\Exception;

use InvalidArgumentException;

/**
 * A request parameter is missing or out of range. {@see $errorCode} is the CONDUCTOR_* code sent to the client.
 */
final class ConductorProcessInvalidRequest extends InvalidArgumentException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
