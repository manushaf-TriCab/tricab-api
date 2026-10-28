<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Auth;

use Psr\Http\Message\ResponseInterface;
use Throwable;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Conductor\Process\Application\Exception\ConductorProcessInvalidRequest;
use TriCab\Core\Conductor\Process\Application\Exception\ConductorProcessItemNotFound;
use TriCab\Core\Conductor\Process\Application\Exception\ConductorProcessMachineNotFound;
use TriCab\Core\Conductor\Process\Application\Exception\ConductorProcessStoreUnavailable;

/**
 * Maps the Conductor process exceptions to the platform error envelope. The 503 message never says why or where.
 */
final class ConductorProcessErrorMapper
{
    public function __construct(
        private readonly JsonResponder $responder,
    ) {
    }

    public function map(Throwable $exception): ResponseInterface
    {
        if ($exception instanceof ConductorProcessInvalidRequest) {
            return $this->responder->error($exception->errorCode, $exception->getMessage(), 422);
        }

        if ($exception instanceof ConductorProcessItemNotFound) {
            return $this->responder->error('CONDUCTOR_ITEM_NOT_FOUND', 'That item is not in the conductor data.', 404);
        }

        if ($exception instanceof ConductorProcessMachineNotFound) {
            return $this->responder->error('CONDUCTOR_MACHINE_NOT_FOUND', 'That machine is not on the conductor map.', 404);
        }

        if ($exception instanceof ConductorProcessStoreUnavailable) {
            error_log('[conductor-process] ' . $exception->getMessage());
        } else {
            error_log('[conductor-process] unexpected: ' . $exception->getMessage());
        }

        return $this->responder->error(
            'CONDUCTOR_STORE_UNAVAILABLE',
            'The conductor data is not available right now.',
            503,
        );
    }
}
