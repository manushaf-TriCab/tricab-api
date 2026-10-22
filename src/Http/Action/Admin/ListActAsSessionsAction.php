<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\UserManagementErrorMapper;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Identity\Application\DTO\ActAs\ActAsSessionSummary;
use TriCab\Core\Identity\Contract\ActAsSessionRepository;

/**
 * Audit view of recent act-as sessions, newest first.
 */
final class ListActAsSessionsAction
{
    private const LIMIT = 100;

    public function __construct(
        private readonly ActAsSessionRepository $sessions,
        private readonly JsonResponder $responder,
        private readonly UserManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($request, $response);

        try {
            return $this->responder->success([
                'items' => array_map(
                    static fn (ActAsSessionSummary $session): array => [
                        'sessionId' => $session->sessionId,
                        'reason' => $session->reason,
                        'startedAt' => $session->startedAt,
                        'expiresAt' => $session->expiresAt,
                        'endedAt' => $session->endedAt,
                        'endReason' => $session->endReason,
                        'admin' => [
                            'publicId' => $session->adminPublicId,
                            'displayName' => $session->adminDisplayName,
                            'emailAddress' => $session->adminEmailAddress,
                        ],
                        'target' => [
                            'publicId' => $session->targetPublicId,
                            'displayName' => $session->targetDisplayName,
                            'emailAddress' => $session->targetEmailAddress,
                        ],
                    ],
                    $this->sessions->listRecent(self::LIMIT),
                ),
            ]);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
