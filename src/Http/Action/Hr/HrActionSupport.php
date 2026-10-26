<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Hr;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;

/** Shared by the HR Employees actions: the signed-in user and the permission lookups. */
trait HrActionSupport
{
    private function user(ServerRequestInterface $request): ?AuthenticatedUser
    {
        $user = $request->getAttribute(AuthenticatedUserAttribute::KEY);

        return $user instanceof AuthenticatedUser ? $user : null;
    }

    private function unauthenticated(JsonResponder $responder): ResponseInterface
    {
        return $responder->error('UNAUTHENTICATED', 'Authentication is required.', 401);
    }

    private function holds(AuthenticatedUser $user, string $permission): bool
    {
        $required = PermissionCode::fromString($permission);
        foreach ($user->permissions as $held) {
            if (PermissionCode::fromString($held)->equals($required)) {
                return true;
            }
        }

        return false;
    }
}
