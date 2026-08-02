<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Authentication;

use Closure;
use TriCab\Api\Config\Settings;
use TriCab\Core\Identity\Contract\GuestAccessTokenValidator;

/**
 * Routes Bearer tokens to Entra or guest validators using unverified iss as a hint only.
 * Never falls back across providers after a failed full validation.
 * Guest validator is resolved lazily so app boot does not require a live database.
 */
final class CompositeBearerTokenValidator
{
    /** @var Closure(): GuestAccessTokenValidator|null */
    private readonly ?Closure $guestTokenValidatorFactory;

    /**
     * @param Closure(): GuestAccessTokenValidator|null $guestTokenValidatorFactory
     */
    public function __construct(
        private readonly EntraTokenValidator $entraTokenValidator,
        ?Closure $guestTokenValidatorFactory,
        private readonly Settings $settings,
    ) {
        $this->guestTokenValidatorFactory = $guestTokenValidatorFactory;
    }

    public function validate(string $accessToken): BearerAuthResult
    {
        if ($this->shouldValidateAsGuest($accessToken)) {
            $guestValidator = $this->resolveGuestValidator();
            if ($guestValidator === null) {
                throw AuthTokenValidationFailed::invalid('Guest authentication is not configured.');
            }

            $validated = $guestValidator->validate($accessToken);

            return new BearerAuthResult(
                identity: GuestVerifiedIdentityFactory::fromValidatedToken($validated),
                guestToken: $validated,
            );
        }

        return new BearerAuthResult(
            identity: $this->entraTokenValidator->validate($accessToken),
        );
    }

    private function shouldValidateAsGuest(string $accessToken): bool
    {
        if (!$this->settings->isGuestJwtConfigured() || $this->guestTokenValidatorFactory === null) {
            return false;
        }

        $issuer = $this->peekIssuer($accessToken);

        return $issuer === $this->settings->guestJwtIssuer;
    }

    private function resolveGuestValidator(): ?GuestAccessTokenValidator
    {
        if ($this->guestTokenValidatorFactory === null) {
            return null;
        }

        return ($this->guestTokenValidatorFactory)();
    }

    private function peekIssuer(string $accessToken): ?string
    {
        $parts = explode('.', $accessToken);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = self::base64UrlDecode($parts[1]);
        if ($payload === null) {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return null;
        }

        $issuer = $decoded['iss'] ?? null;

        return is_string($issuer) && $issuer !== '' ? $issuer : null;
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
