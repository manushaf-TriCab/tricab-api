<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Auth;

use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportVisibility;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportAccessDenied;
use TriCab\Core\CustomerContactReport\Module;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

final class CustomerContactReportActor
{
    public static function fromRequest(ServerRequestInterface $request): AuthenticatedUser
    {
        $user = $request->getAttribute(AuthenticatedUserAttribute::KEY);
        if (!$user instanceof AuthenticatedUser) {
            throw new RuntimeException(
                'AuthenticatedUser attribute is missing; ensure RequirePermissionMiddleware is applied to this route.',
            );
        }

        return $user;
    }

    public static function assertAccess(AuthenticatedUser $user): void
    {
        if (!in_array(Module::PERMISSION_ACCESS, $user->permissions, true)) {
            throw new CustomerContactReportAccessDenied();
        }
    }

    public static function visibility(AuthenticatedUser $user): CustomerContactReportVisibility
    {
        return new CustomerContactReportVisibility(
            readAll: in_array(Module::PERMISSION_READ_ALL, $user->permissions, true),
            userId: $user->userId,
            email: $user->emailAddress,
        );
    }
}
