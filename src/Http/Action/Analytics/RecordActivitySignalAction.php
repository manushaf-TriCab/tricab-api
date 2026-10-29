<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Analytics;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\ActingAdminAttribute;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Analytics\Application\Exception\ActivityRateLimited;
use TriCab\Core\Analytics\Application\Exception\AnalyticsUnavailable;
use TriCab\Core\Analytics\Application\Exception\InvalidActivityRequest;
use TriCab\Core\Analytics\Application\Service\RecordActivityService;
use TriCab\Core\Identity\Application\DTO\ActAs\ActingAdmin;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

/**
 * POST /api/v1/activity/signals
 *
 * Body: { sessionId: uuid, platform: web|mobile, module: code, event: view|heartbeat|background|end }.
 * The user comes from authentication, never from the body. The response tells the client whether to keep
 * sending, so collection can be switched off server-side without a client release.
 */
final class RecordActivitySignalAction
{
    public function __construct(
        private readonly RecordActivityService $activity,
        private readonly JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        $user = $request->getAttribute(AuthenticatedUserAttribute::KEY);
        if (!$user instanceof AuthenticatedUser) {
            return $this->responder->error('UNAUTHENTICATED', 'Authentication is required.', 401);
        }

        $body = $request->getParsedBody();

        try {
            $result = $this->activity->record(
                $user,
                is_array($body) ? $body : [],
                $request->getAttribute(ActingAdminAttribute::KEY) instanceof ActingAdmin,
            );
        } catch (InvalidActivityRequest $exception) {
            return $this->responder->error('VALIDATION_FAILED', $exception->getMessage(), 422);
        } catch (ActivityRateLimited $exception) {
            return $this->responder->errorWith(
                'RATE_LIMITED',
                'Too many activity requests.',
                429,
                [],
                ['Retry-After' => (string) $exception->retryAfterSeconds],
            );
        } catch (AnalyticsUnavailable $exception) {
            error_log($exception->getMessage());

            return $this->responder->error('ANALYTICS_UNAVAILABLE', 'Activity could not be recorded.', 503);
        }

        return $this->responder->success($result, 200, ['Cache-Control' => 'no-store']);
    }
}
