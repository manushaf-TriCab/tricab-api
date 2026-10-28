<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Auth;

use Psr\Http\Message\ResponseInterface;
use Throwable;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Conductor\Schedule\Application\Exception\ConductorScheduleInvalidRequest;
use TriCab\Core\Conductor\Schedule\Application\Exception\ConductorScheduleOpNotFound;
use TriCab\Core\Conductor\Schedule\Application\Exception\ConductorScheduleBatchNotFound;
use TriCab\Core\Conductor\Schedule\Application\Exception\ConductorScheduleStoreUnavailable;

/**
 * Maps the Conductor schedule exceptions to the platform error envelope. The 503 message never says why or where.
 */
final class ConductorScheduleErrorMapper
{
    public function __construct(
        private readonly JsonResponder $responder,
    ) {
    }

    public function map(Throwable $exception): ResponseInterface
    {
        if ($exception instanceof ConductorScheduleInvalidRequest) {
            return $this->responder->error($exception->errorCode, $exception->getMessage(), 422);
        }

        if ($exception instanceof ConductorScheduleOpNotFound) {
            return $this->responder->error('CONDUCTOR_OP_NOT_FOUND', 'That operation is not in the conductor schedule.', 404);
        }

        if ($exception instanceof ConductorScheduleBatchNotFound) {
            return $this->responder->error('CONDUCTOR_BATCH_NOT_FOUND', 'That batch is not in the conductor schedule.', 404);
        }

        if ($exception instanceof ConductorScheduleStoreUnavailable) {
            error_log('[conductor-schedule] ' . $exception->getMessage());
        } else {
            error_log('[conductor-schedule] unexpected: ' . $exception->getMessage());
        }

        return $this->responder->error(
            'CONDUCTOR_SCHEDULE_STORE_UNAVAILABLE',
            'The conductor schedule data is not available right now.',
            503,
        );
    }
}
