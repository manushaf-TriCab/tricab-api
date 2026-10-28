<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Conductor\Process;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Request\Admin\QueryParams;

/**
 * Shared by the five Conductor process actions: reads the query string and turns a service call into the envelope.
 * The using class has a `$responder` (JsonResponder) and `$errors` (ConductorProcessErrorMapper).
 */
trait RespondsToConductorProcess
{
    /**
     * @return array<string, mixed>
     */
    private function query(ServerRequestInterface $request): array
    {
        return QueryParams::normalize($request->getQueryParams());
    }

    /**
     * @param array<string, mixed> $query
     * @return ?string trimmed, null when absent or blank
     */
    private function param(array $query, string $key): ?string
    {
        return QueryParams::stringOrNull($query, $key);
    }

    /**
     * @param Closure(): array<string, mixed> $work
     */
    private function respond(Closure $work): ResponseInterface
    {
        try {
            return $this->responder->success($work());
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
