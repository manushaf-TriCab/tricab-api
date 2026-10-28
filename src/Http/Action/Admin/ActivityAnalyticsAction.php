<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Analytics\Application\Exception\ActivityRateLimited;
use TriCab\Core\Analytics\Application\Exception\AnalyticsUnavailable;
use TriCab\Core\Analytics\Application\Exception\InvalidActivityRequest;
use TriCab\Core\Analytics\Application\Service\ActivityAnalyticsService;
use TriCab\Core\Analytics\Contract\ActivityThrottle;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

/**
 * GET /api/v1/admin/analytics/active-users?range=today|7d|30d|90d
 * GET /api/v1/admin/analytics/live-users
 * GET /api/v1/admin/analytics/module-activity?range=today|7d|30d&sort=live|users
 *
 * ANALYTICS.READ. Aggregate counts only; no response names or identifies a user.
 */
final class ActivityAnalyticsAction
{
    private const READ_LIMIT = 240;
    private const READ_WINDOW_SECONDS = 60;

    public function __construct(
        private readonly ActivityAnalyticsService $analytics,
        private readonly ActivityThrottle $throttle,
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

        $query = $request->getQueryParams();
        $range = is_string($query['range'] ?? null) ? $query['range'] : null;
        $path = $request->getUri()->getPath();

        try {
            if (!$this->throttle->allow('read:' . $user->userId, self::READ_LIMIT, self::READ_WINDOW_SECONDS)) {
                throw new ActivityRateLimited(self::READ_WINDOW_SECONDS);
            }

            $data = match (true) {
                str_ends_with($path, '/active-users') => $this->analytics->activeUsers($range ?? '30d'),
                str_ends_with($path, '/live-users') => $this->analytics->liveUsers(),
                default => $this->analytics->moduleActivity(
                    $range ?? 'today',
                    is_string($query['sort'] ?? null) ? $query['sort'] : 'live',
                ),
            };
        } catch (InvalidActivityRequest $exception) {
            return $this->responder->error('VALIDATION_FAILED', $exception->getMessage(), 422);
        } catch (ActivityRateLimited $exception) {
            return $this->responder->errorWith(
                'RATE_LIMITED',
                'Too many analytics requests.',
                429,
                [],
                ['Retry-After' => (string) $exception->retryAfterSeconds],
            );
        } catch (AnalyticsUnavailable $exception) {
            error_log($exception->getMessage());

            return $this->responder->error('ANALYTICS_UNAVAILABLE', 'Activity analytics are unavailable.', 503);
        }

        return $this->responder->success($data, 200, ['Cache-Control' => 'private, no-store']);
    }
}
