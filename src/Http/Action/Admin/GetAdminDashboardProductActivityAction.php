<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Admin\Dashboard\AdminDashboardQueryRepository;
use TriCab\Api\Http\Response\JsonResponder;

/**
 * GET /api/v1/admin/dashboard/product-activity?range=7d|30d|90d
 */
final class GetAdminDashboardProductActivityAction
{
    public function __construct(
        private readonly AdminDashboardQueryRepository $dashboard,
        private readonly JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $query = $request->getQueryParams();
            $range = is_string($query['range'] ?? null) ? (string) $query['range'] : '30d';

            return $this->responder->success($this->dashboard->fetchProductActivity($range));
        } catch (Throwable) {
            return $this->responder->error(
                'DASHBOARD_ACTIVITY_UNAVAILABLE',
                'Unable to load product activity series.',
                500,
            );
        }
    }
}
