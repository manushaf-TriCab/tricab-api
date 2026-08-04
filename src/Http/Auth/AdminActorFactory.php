<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Auth;

use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use TriCab\Api\Http\Request\GuestAuthRequestMetadata;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;

/**
 * Builds a Core ActorContext from the AuthenticatedUser attached by
 * RequirePermissionMiddleware, plus request correlation/network metadata.
 */
final class AdminActorFactory
{
    public static function fromRequest(ServerRequestInterface $request): ActorContext
    {
        $user = $request->getAttribute(AuthenticatedUserAttribute::KEY);

        if (!$user instanceof AuthenticatedUser) {
            throw new RuntimeException(
                'AuthenticatedUser attribute is missing; ensure RequirePermissionMiddleware is applied to this route.',
            );
        }

        return new ActorContext(
            userId: $user->userId,
            publicId: $user->publicId,
            emailAddress: $user->emailAddress,
            correlationId: self::correlationId($request),
            ipAddress: GuestAuthRequestMetadata::clientIp($request),
            userAgent: GuestAuthRequestMetadata::userAgent($request),
        );
    }

    private static function correlationId(ServerRequestInterface $request): ?string
    {
        foreach (['X-Request-Id', 'X-Correlation-Id'] as $header) {
            $value = trim($request->getHeaderLine($header));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
