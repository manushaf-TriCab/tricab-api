<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Config\Settings;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Finance\Module;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

/**
 * GET /api/v1/finance/me: what the signed-in person can open in finance.
 *
 * The launcher is built from this: one entry per app, saying whether the app
 * is switched on and whether the caller holds its access permission. It returns
 * no record. Gated on `FINANCE.ACCESS`.
 */
final class GetFinanceAccessAction
{
    public function __construct(
        private readonly Settings $settings,
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

        $held = array_values(array_filter(
            $user->permissions,
            static fn (string $permission): bool => str_starts_with($permission, 'FINANCE.'),
        ));

        $apps = [];
        foreach (Module::APPS as $key => $app) {
            $apps[] = [
                'key' => $key,
                'name' => $app['name'],
                'path' => $app['path'],
                'enabled' => $this->settings->financeAppEnabled($key),
                'canAccess' => in_array(Module::appAccessPermission($key), $user->permissions, true),
            ];
        }

        return $this->responder->success([
            'permissions' => $held,
            'apps' => $apps,
        ]);
    }
}
