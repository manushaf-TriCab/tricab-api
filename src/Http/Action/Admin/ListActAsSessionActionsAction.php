<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\UserManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Identity\Application\DTO\ActAs\ActAsActionEntry;
use TriCab\Core\Identity\Application\Exception\ActAsSessionNotFound;
use TriCab\Core\Identity\Contract\ActAsSessionRepository;

/**
 * The changes logged while one act-as session was open, oldest first.
 */
final class ListActAsSessionActionsAction
{
    private const LIMIT = 500;

    public function __construct(
        private readonly ActAsSessionRepository $sessions,
        private readonly JsonResponder $responder,
        private readonly UserManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $sessionId = strtolower(RouteParams::require($request, 'id'));
            if ($this->sessions->findById($sessionId) === null) {
                throw ActAsSessionNotFound::forId($sessionId);
            }

            return $this->responder->success([
                'items' => array_map(
                    static fn (ActAsActionEntry $entry): array => [
                        'id' => $entry->id,
                        'method' => $entry->method,
                        'routePattern' => $entry->routePattern,
                        'path' => $entry->path,
                        'statusCode' => $entry->statusCode,
                        'durationMs' => $entry->durationMs,
                        'requestId' => $entry->requestId,
                        'occurredAt' => $entry->occurredAt,
                    ],
                    $this->sessions->listActions($sessionId, self::LIMIT),
                ),
            ]);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
