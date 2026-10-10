<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap;

use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Finance\Ap\Domain\ApActor;
use TriCab\Core\Finance\Ap\Domain\ApMailboxScope;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Access\FinanceAccessScopeReader;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

/**
 * Builds the {@see ApActor} of a request: the person from the bearer token, their permissions, and
 * the mailbox scope from `finance.access_scopes` for the application `AP` (question Q11). No scope
 * rows means every mailbox, and a person holding `FINANCE.AP.SETTINGS.MANAGE` (the source's
 * administrator) is unrestricted whatever rows they have.
 *
 * Inject it into an action and call `actorOf($request)`; null means the request carries no signed-in
 * person (answer 401). The route's own permission has already been checked by `RequirePermission`.
 */
final class ApActorResolver
{
    public function __construct(private readonly FinanceAccessScopeReader $scopes)
    {
    }

    public function actorOf(ServerRequestInterface $request): ?ApActor
    {
        $user = $request->getAttribute(AuthenticatedUserAttribute::KEY);
        if (!$user instanceof AuthenticatedUser) {
            return null;
        }
        $isAdmin = in_array(Module::PERMISSION_AP_SETTINGS_MANAGE, $user->permissions, true);
        $scope = $isAdmin
            ? ApMailboxScope::unrestricted()
            : ApMailboxScope::limitedTo($this->scopes->forUser($user->userId, Module::APP_AP)->mailboxes);

        return new ApActor($user->userId, $user->displayName, $user->emailAddress, $user->permissions, $scope, $isAdmin);
    }
}
