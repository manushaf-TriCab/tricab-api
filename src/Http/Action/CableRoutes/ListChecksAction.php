<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\CableRoutes;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Auth\CableRoutesErrorMapper;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\CableRoutes\Application\Service\CableRoutesQueryService;

final class ListChecksAction
{
    use RespondsToCableRoutes;

    public function __construct(
        private readonly CableRoutesQueryService $service,
        private readonly JsonResponder $responder,
        private readonly CableRoutesErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        $query = $this->query($request);

        return $this->respond(fn (): array => $this->service->checks($this->param($query, 'kind'), $this->param($query, 'code'), $this->param($query, 'page'), $this->param($query, 'pageSize')));
    }
}
