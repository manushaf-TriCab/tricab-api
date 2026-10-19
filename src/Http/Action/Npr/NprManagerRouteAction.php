<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Npr;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Npr\Application\Service\ManagerRoutingService;
use TriCab\Core\Npr\Domain\Exception\InvalidNprInput;
use TriCab\Core\Npr\Domain\Exception\NprWorkflowRefused;

/**
 * NPR manager routing, for administrators (`NPR.ADMIN`). The route names the step.
 *
 *  - `list`   GET  /npr/admin/manager-routes: every route with whether it is active today;
 *  - `create` POST /npr/admin/manager-routes `{branch, managerUserId|managerEmail, isDelegate?, validFrom?, validTo?}`:
 *             201, or 409 `NPR_ROUTE_OVERLAP` when a route of the same kind shares a day;
 *  - `update` PUT  /npr/admin/manager-routes/{id} `{validTo}` ends (or with null re-opens) a route, or
 *             `{replacement: {managerUserId|managerEmail, validFrom?, validTo?}, validTo?}` ends it the day
 *             before the replacement starts and adds the replacement. Answers with the changed routes.
 *
 * The manager must be an active platform user.
 */
final class NprManagerRouteAction
{
    use NprActionSupport;

    public function __construct(
        private readonly ManagerRoutingService $routing,
        private readonly JsonResponder $responder,
    ) {
    }

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response);
        $user = $this->user($request);
        if ($user === null) {
            return $this->unauthenticated($this->responder);
        }
        $today = $this->routing->today();

        try {
            switch ($args['step'] ?? '') {
                case 'list':
                    return $this->responder->success([
                        'items' => array_map(static fn ($route): array => NprRequestPresenter::route($route, $today), $this->routing->list()),
                    ]);
                case 'create':
                    $route = $this->routing->create($user, $this->body($request));

                    return $this->responder->success(NprRequestPresenter::route($route, $today), 201);
                default:
                    $id = ctype_digit($args['id'] ?? '') ? (int) $args['id'] : 0;
                    $changed = $id > 0 ? $this->routing->update($user, $id, $this->body($request)) : null;
                    if ($changed === null) {
                        return $this->responder->error('NPR_NOT_FOUND', 'Manager route not found.', 404);
                    }

                    return $this->responder->success([
                        'items' => array_map(static fn ($route): array => NprRequestPresenter::route($route, $today), $changed),
                    ]);
            }
        } catch (InvalidNprInput|NprWorkflowRefused $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
