<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Admin\Dashboard\AdminDashboardQueryRepository;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

/**
 * GET /api/v1/admin/dashboard
 *
 * Returns operational aggregates. Sections are gated by the caller's permissions:
 * PRODUCT.READ, USER.READ, CUSTOMER.CONTACT.READ (contacts) / CUSTOMER.READ.
 */
final class GetAdminDashboardAction
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
            $user = $request->getAttribute(AuthenticatedUserAttribute::KEY);
            if (!$user instanceof AuthenticatedUser) {
                return $this->responder->error('UNAUTHENTICATED', 'Authentication is required.', 401);
            }

            $query = $request->getQueryParams();
            $range = is_string($query['range'] ?? null) ? (string) $query['range'] : '30d';
            $snapshot = $this->dashboard->fetchSnapshot($range);

            $canProducts = $this->has($user, 'PRODUCT.READ');
            $canUsers = $this->has($user, 'USER.READ');
            $canContacts = $this->has($user, 'CUSTOMER.CONTACT.READ') || $this->has($user, 'CUSTOMER.READ');
            $canAudit = $this->has($user, 'PRODUCT.AUDIT.VIEW') || $canProducts;

            if (!$canProducts) {
                $snapshot['summary']['totalProducts'] = null;
                $snapshot['summary']['publishedProducts'] = null;
                $snapshot['summary']['draftProducts'] = null;
                $snapshot['summary']['catalogueHealthPercent'] = null;
                $snapshot['summary']['blockerCount'] = null;
                $snapshot['productStatus'] = null;
                $snapshot['catalogueHealth'] = null;
                $snapshot['attention'] = null;
                $snapshot['recentProducts'] = null;
                $snapshot['productActivity'] = null;
            }

            if (!$canUsers) {
                $snapshot['summary']['users'] = null;
                $snapshot['recentUsers'] = null;
            }

            if (!$canContacts) {
                $snapshot['summary']['contacts'] = null;
                $snapshot['summary']['contactsNew'] = null;
                $snapshot['recentContacts'] = null;
            }

            if (!$canAudit) {
                $snapshot['recentActivity'] = null;
            }

            $snapshot['capabilities'] = [
                'products' => $canProducts,
                'users' => $canUsers,
                'contacts' => $canContacts,
                'activity' => $canAudit,
            ];

            return $this->responder->success($snapshot);
        } catch (Throwable $exception) {
            error_log('Admin dashboard failed: ' . $exception->getMessage() . "\n" . $exception->getTraceAsString());

            return $this->responder->error(
                'DASHBOARD_UNAVAILABLE',
                'Unable to load admin dashboard aggregates.',
                500,
            );
        }
    }

    private function has(AuthenticatedUser $user, string $permission): bool
    {
        $needle = strtoupper($permission);
        foreach ($user->permissions as $code) {
            if (strtoupper($code) === $needle) {
                return true;
            }
        }

        return false;
    }
}
