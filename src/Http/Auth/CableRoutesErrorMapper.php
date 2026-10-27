<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Auth;

use Psr\Http\Message\ResponseInterface;
use Throwable;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\CableRoutes\Application\Exception\CableRoutesInvalidRequest;
use TriCab\Core\CableRoutes\Application\Exception\CableRoutesItemNotFound;
use TriCab\Core\CableRoutes\Application\Exception\CableRoutesMachineNotFound;
use TriCab\Core\CableRoutes\Application\Exception\CableRoutesStoreUnavailable;

/**
 * Maps the Cable routes exceptions to the platform error envelope. The 503 message never says why or where.
 */
final class CableRoutesErrorMapper
{
    public function __construct(
        private readonly JsonResponder $responder,
    ) {
    }

    public function map(Throwable $exception): ResponseInterface
    {
        if ($exception instanceof CableRoutesInvalidRequest) {
            return $this->responder->error($exception->errorCode, $exception->getMessage(), 422);
        }

        if ($exception instanceof CableRoutesItemNotFound) {
            return $this->responder->error('CABLE_ROUTES_ITEM_NOT_FOUND', 'That cable is not in the cable routes data.', 404);
        }

        if ($exception instanceof CableRoutesMachineNotFound) {
            return $this->responder->error('CABLE_ROUTES_MACHINE_NOT_FOUND', 'That machine is not in the cable routes data.', 404);
        }

        if ($exception instanceof CableRoutesStoreUnavailable) {
            error_log('[cable-routes] ' . $exception->getMessage());
        } else {
            error_log('[cable-routes] unexpected: ' . $exception->getMessage());
        }

        return $this->responder->error(
            'CABLE_ROUTES_STORE_UNAVAILABLE',
            'The cable routes data is not available right now.',
            503,
        );
    }
}
