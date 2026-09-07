<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Auth;

use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

final class ExpenseActorFactory
{
    public static function fromRequest(ServerRequestInterface $request): ExpenseActor
    {
        $user = $request->getAttribute(AuthenticatedUserAttribute::KEY);
        if (!$user instanceof AuthenticatedUser) {
            throw new RuntimeException('Authenticated user required.');
        }

        return new ExpenseActor(
            userId: UserId::fromInt($user->userId),
            publicId: $user->publicId,
            emailAddress: $user->emailAddress,
            permissions: $user->permissions,
            roles: $user->roles,
        );
    }
}