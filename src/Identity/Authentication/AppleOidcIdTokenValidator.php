<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Authentication;

use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use RuntimeException;
use stdClass;
use TriCab\Api\Config\Settings;
use TriCab\Core\Identity\Application\DTO\VerifiedAppleIdToken;
use TriCab\Core\Identity\Application\Exception\AppleIdTokenInvalid;
use TriCab\Core\Identity\Contract\AppleIdTokenValidator;
use TriCab\Core\Identity\Contract\AppleNativeIdTokenValidator;
use UnexpectedValueException;

/**
 * Validates Apple ID tokens using Apple's OpenID JWKS.
 */
final class AppleOidcIdTokenValidator implements AppleIdTokenValidator, AppleNativeIdTokenValidator
{
    private const ISSUER = 'https://appleid.apple.com';

    /** @var array<string, Key>|null */
    private ?array $cachedKeys = null;

    private int $cachedKeysFetchedAt = 0;

    public function __construct(
        private readonly Settings $settings,
        private readonly AppleJwksProvider $jwksClient = new AppleJwksClient(),
        private readonly int $clockSkewSeconds = 60,
    ) {
    }

    public function validate(string $idToken, string $expectedNonce): VerifiedAppleIdToken
    {
        if (!$this->settings->isAppleAuthConfigured()) {
            throw AppleIdTokenInvalid::notConfigured();
        }

        $decoded = $this->decode($idToken);
        $this->assertAudience($decoded, $this->settings->appleClientId);
        $this->assertIssuer($decoded);
        $this->assertNonce($decoded, $expectedNonce);

        $subject = $this->stringClaim($decoded, 'sub');
        $email = strtolower(trim($this->stringClaim($decoded, 'email')));
        $emailVerified = $this->booleanClaim($decoded, 'email_verified');

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw AppleIdTokenInvalid::missingEmail();
        }

        if (!$emailVerified) {
            throw AppleIdTokenInvalid::emailNotVerified();
        }

        return new VerifiedAppleIdToken(
            subjectId: $subject,
            emailAddress: $email,
            emailVerified: true,
        );
    }

    public function validateNative(string $idToken): VerifiedAppleIdToken
    {
        if (!$this->settings->isAppleNativeAuthConfigured()) {
            throw AppleIdTokenInvalid::notConfigured();
        }

        $decoded = $this->decode($idToken);
        $this->assertAudience($decoded, $this->settings->appleNativeClientId);
        $this->assertIssuer($decoded);

        $subject = $this->stringClaim($decoded, 'sub');
        $emailRaw = $decoded->email ?? null;
        $email = is_string($emailRaw) ? strtolower(trim($emailRaw)) : '';
        $emailVerified = true;
        if ($email !== '') {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw AppleIdTokenInvalid::missingEmail();
            }
            try {
                $emailVerified = $this->booleanClaim($decoded, 'email_verified');
            } catch (AppleIdTokenInvalid) {
                $emailVerified = true;
            }
        }

        return new VerifiedAppleIdToken(
            subjectId: $subject,
            emailAddress: $email,
            emailVerified: $emailVerified,
        );
    }

    private function decode(string $idToken): stdClass
    {
        $token = trim($idToken);
        if ($token === '' || substr_count($token, '.') !== 2) {
            throw AppleIdTokenInvalid::create('Malformed Apple ID token.');
        }

        $previousLeeway = JWT::$leeway;
        JWT::$leeway = $this->clockSkewSeconds;

        try {
            $keys = $this->resolveSigningKeys();

            return JWT::decode($token, $keys);
        } catch (ExpiredException) {
            throw AppleIdTokenInvalid::expired();
        } catch (BeforeValidException) {
            throw AppleIdTokenInvalid::create('Apple ID token is not yet valid.');
        } catch (SignatureInvalidException) {
            throw AppleIdTokenInvalid::create('Apple ID token signature is invalid.');
        } catch (UnexpectedValueException $exception) {
            throw AppleIdTokenInvalid::create($exception->getMessage());
        } catch (RuntimeException $exception) {
            throw AppleIdTokenInvalid::create($exception->getMessage());
        } finally {
            JWT::$leeway = $previousLeeway;
        }
    }

    private function assertAudience(stdClass $claims, string $expectedAudience): void
    {
        $audience = $claims->aud ?? null;
        $ok = is_string($audience)
            ? $audience === $expectedAudience
            : (is_array($audience) && in_array($expectedAudience, $audience, true));

        if (!$ok) {
            throw AppleIdTokenInvalid::wrongAudience();
        }
    }

    private function assertIssuer(stdClass $claims): void
    {
        $issuer = $claims->iss ?? null;
        if (!is_string($issuer) || $issuer !== self::ISSUER) {
            throw AppleIdTokenInvalid::wrongIssuer();
        }
    }

    private function assertNonce(stdClass $claims, string $expectedNonce): void
    {
        $nonce = $claims->nonce ?? null;
        // Apple returns SHA-256(hex) of the raw client nonce in the ID token.
        $expectedHash = hash('sha256', trim($expectedNonce));
        if (!is_string($nonce) || !hash_equals($expectedHash, trim($nonce))) {
            throw AppleIdTokenInvalid::nonceMismatch();
        }
    }

    private function stringClaim(stdClass $claims, string $name): string
    {
        $value = $claims->{$name} ?? null;
        if (!is_string($value) || trim($value) === '') {
            if ($name === 'email') {
                throw AppleIdTokenInvalid::missingEmail();
            }

            throw AppleIdTokenInvalid::create(sprintf('Apple ID token is missing claim "%s".', $name));
        }

        return trim($value);
    }

    private function booleanClaim(stdClass $claims, string $name): bool
    {
        $value = $claims->{$name} ?? null;
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === 'true' || $normalized === '1') {
                return true;
            }
            if ($normalized === 'false' || $normalized === '0') {
                return false;
            }
        }

        if (is_int($value)) {
            return $value === 1;
        }

        throw AppleIdTokenInvalid::create(sprintf('Apple ID token is missing claim "%s".', $name));
    }

    /**
     * @return array<string, Key>
     */
    private function resolveSigningKeys(): array
    {
        if (
            $this->cachedKeys !== null
            && (time() - $this->cachedKeysFetchedAt) < 3600
        ) {
            return $this->cachedKeys;
        }

        $jwks = $this->jwksClient->fetch();
        /** @var array<string, Key> $keys */
        $keys = JWK::parseKeySet($jwks);
        $this->cachedKeys = $keys;
        $this->cachedKeysFetchedAt = time();

        return $keys;
    }
}
