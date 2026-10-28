<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Auth;

use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Api\Identity\Authentication\VerifiedIdentityAttribute;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Application\DTO\VerifiedIdentity;
use TriCab\Core\Identity\Application\Exception\IdentityAmbiguous;
use TriCab\Core\Identity\Application\Exception\IdentityConflict;
use TriCab\Core\Identity\Application\Exception\UserNotRegistered;
use TriCab\Core\Identity\Application\Handler\ResolveAuthenticatedUserHandler;
use TriCab\Core\Identity\Domain\Exception\AuthenticationNotAllowed;
use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;
use TriCab\Core\Identity\Domain\Service\PermissionAssurancePolicy;
use TriCab\Core\Identity\Domain\ValueObject\AuthenticationAssurance;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;

/**
 * Resolves AuthenticatedUser for public customer routes that may only have VerifiedIdentity
 * (Entra Bearer or DEV auth). Never trusts client-supplied user/customer IDs.
 *
 * The returned user carries only the permissions this session's sign-in
 * assurance satisfies (PermissionAssurancePolicy). The commerce and pricing
 * services see nothing but the user, so this is where a weak sign-in loses
 * staff price-as and margin override.
 */
class ResolveRequestAuthenticatedUser
{
    public function __construct(
        private readonly ResolveAuthenticatedUserHandler $handler,
    ) {
    }

    public function fromRequest(ServerRequestInterface $request): ?AuthenticatedUser
    {
        $user = $this->resolve($request);

        return $user === null ? null : self::scopeToSessionAssurance($user, $request);
    }

    /**
     * While an administrator acts as someone, the VerifiedIdentity is still the
     * administrator's own, so the administrator's sign-in is what counts.
     * Absent or unrecognised providers resolve to LOW.
     */
    public static function sessionAssurance(ServerRequestInterface $request): AuthenticationAssurance
    {
        $verified = $request->getAttribute(VerifiedIdentityAttribute::KEY);

        return $verified instanceof VerifiedIdentity
            ? AuthenticationAssurance::forProvider($verified->provider)
            : AuthenticationAssurance::low();
    }

    public static function scopeToSessionAssurance(AuthenticatedUser $user, ServerRequestInterface $request): AuthenticatedUser
    {
        $assurance = self::sessionAssurance($request);
        $permissions = array_values(array_filter(
            $user->permissions,
            static fn (string $code): bool => self::isEffective($code, $assurance),
        ));

        if (count($permissions) === count($user->permissions)) {
            return $user;
        }

        return new AuthenticatedUser(
            $user->userId,
            $user->publicId,
            $user->emailAddress,
            $user->displayName,
            $user->status,
            $user->roles,
            $permissions,
        );
    }

    private static function isEffective(string $code, AuthenticationAssurance $assurance): bool
    {
        try {
            return PermissionAssurancePolicy::isSatisfiedBy(PermissionCode::fromString($code), $assurance);
        } catch (InvalidIdentityValue) {
            return false;
        }
    }

    private function resolve(ServerRequestInterface $request): ?AuthenticatedUser
    {
        $attached = $request->getAttribute(AuthenticatedUserAttribute::KEY);
        if ($attached instanceof AuthenticatedUser) {
            return $attached;
        }

        $verified = $request->getAttribute(VerifiedIdentityAttribute::KEY);
        if (!$verified instanceof VerifiedIdentity) {
            return null;
        }

        try {
            return $this->handler->handle($verified);
        } catch (UserNotRegistered|IdentityConflict|IdentityAmbiguous|AuthenticationNotAllowed) {
            return null;
        }
    }
}
