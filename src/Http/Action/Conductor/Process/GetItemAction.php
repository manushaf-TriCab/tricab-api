<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Conductor\Process;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Auth\ConductorProcessErrorMapper;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Conductor\Process\Application\Service\ConductorProcessQueryService;

final class GetItemAction
{
    use RespondsToConductorProcess;

    public function __construct(
        private readonly ConductorProcessQueryService $service,
        private readonly JsonResponder $responder,
        private readonly ConductorProcessErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        $query = $this->query($request);

        return $this->respond(fn (): array => $this->service->item($this->param($query, 'item'), $this->param($query, 'length')));
    }
}
