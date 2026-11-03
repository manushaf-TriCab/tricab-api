<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Hr;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Hr\Employees\Directory\DepartmentMapRefused;
use TriCab\Api\Hr\Employees\Directory\DepartmentMapService;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Response\JsonResponder;

/**
 * PUT /api/v1/hr/department-map: sets the clean name and cost centre of one raw
 * value ({kind, raw, cleanName, costCentre}); a blank cleanName and costCentre
 * removes the mapping. Gated on `HR.EMPLOYEES.SYNC`; every change is audited.
 */
final class SaveHrDepartmentMapAction
{
    use HrActionSupport;

    public function __construct(
        private readonly DepartmentMapService $map,
        private readonly JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        if ($this->user($request) === null) {
            return $this->unauthenticated($this->responder);
        }

        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        foreach (['kind', 'raw', 'cleanName', 'costCentre'] as $field) {
            if (isset($body[$field]) && !is_string($body[$field])) {
                return $this->responder->error('VALIDATION_FAILED', $field . ' must be text.', 422);
            }
        }

        try {
            return $this->responder->success($this->map->save(
                AdminActorFactory::fromRequest($request),
                is_string($body['kind'] ?? null) ? $body['kind'] : '',
                is_string($body['raw'] ?? null) ? $body['raw'] : '',
                is_string($body['cleanName'] ?? null) ? $body['cleanName'] : null,
                is_string($body['costCentre'] ?? null) ? $body['costCentre'] : null,
            ));
        } catch (DepartmentMapRefused $refusal) {
            return $this->responder->error($refusal->errorCode, $refusal->getMessage(), $refusal->status);
        }
    }
}
