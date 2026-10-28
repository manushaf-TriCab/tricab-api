<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Conductor\Schedule;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Auth\ConductorScheduleErrorMapper;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Conductor\Schedule\Application\Service\ConductorScheduleQueryService;

final class ListIssuesAction
{
    use RespondsToConductorSchedule;

    public function __construct(
        private readonly ConductorScheduleQueryService $service,
        private readonly JsonResponder $responder,
        private readonly ConductorScheduleErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        $query = $this->query($request);

        return $this->respond(fn (): array => $this->service->issues(
            $this->param($query, 'kind'),
            $this->param($query, 'severity'),
        ));
    }
}
