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
use TriCab\Core\Identity\Application\DTO\VerifiedGoogleIdToken;
use TriCab\Core\Identity\Application\Exception\GoogleIdTokenInvalid;
use TriCab\Core\Identity\Contract\GoogleIdTokenValidator;
use UnexpectedValueException;

/**
 * Validates Google ID tokens using Google's OpenID JWKS.
 */
final class GoogleOidcIdTokenValidator implements GoogleIdTokenValidator
{
    private const ALLOWED_ISSUERS = [
        'https://accounts.google.com',
        'accounts.google.com',
    ];

    /** @var array<string, Key>|null */
    private ?array $cachedKeys = null;

    private int $cachedKeysFetchedAt = 0;

    public function __construct(
        private readonly string|array $googleClientIds,
        private readonly GoogleJwksProvider $jwksClient = new GoogleJwksClient(),
        private readonly int $clockSkewSeconds = 60,
    ) {
    }

    /**
     * @return list<string>
     */
    private function allowedAudiences(): array
    {
        $raw = is_array($this->googleClientIds) ? $this->googleClientIds : [$this->googleClientIds];
        $ids = [];
        foreach ($raw as $id) {
            if (!is_string($id)) {
                continue;
            }
            $trimmed = trim($id);
            if ($trimmed !== '' && !in_array($trimmed, $ids, true)) {
                $ids[] = $trimmed;
            }
        }

        return $ids;
    }

    public function validate(string $idToken): VerifiedGoogleIdToken
    {
        if ($this->allowedAudiences() === []) {
            throw GoogleIdTokenInvalid::notConfigured();
        }

        $token = trim($idToken);
        if ($token === '' || substr_count($token, '.') !== 2) {
            throw GoogleIdTokenInvalid::create('Malformed Google ID token.');
        }

        $previousLeeway = JWT::$leeway;
        JWT::$leeway = $this->clockSkewSeconds;

        try {
            $keys = $this->resolveSigningKeys();
            $decoded = JWT::decode($token, $keys);
        } catch (ExpiredException) {
            throw GoogleIdTokenInvalid::create('Google ID token has expired.');
        } catch (BeforeValidException) {
            throw GoogleIdTokenInvalid::create('Google ID token is not yet valid.');
        } catch (SignatureInvalidException) {
            throw GoogleIdTokenInvalid::create('Google ID token signature is invalid.');
        } catch (UnexpectedValueException $exception) {
            throw GoogleIdTokenInvalid::create($exception->getMessage());
        } catch (RuntimeException $exception) {
            throw GoogleIdTokenInvalid::create($exception->getMessage());
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        $this->assertAudience($decoded);
        $this->assertIssuer($decoded);

        $subject = $this->stringClaim($decoded, 'sub');
        $email = strtolower(trim($this->stringClaim($decoded, 'email')));
        $emailVerified = $this->booleanClaim($decoded, 'email_verified');

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw GoogleIdTokenInvalid::create('Google ID token is missing a verified email.');
        }

        return new VerifiedGoogleIdToken(
            subjectId: $subject,
            emailAddress: $email,
            emailVerified: $emailVerified,
        );
    }

    private function assertAudience(stdClass $claims): void
    {
        $audience = $claims->aud ?? null;
        $allowed = $this->allowedAudiences();
        $ok = is_string($audience)
            ? in_array($audience, $allowed, true)
            : (is_array($audience) && array_intersect($allowed, $audience) !== []);

        if (!$ok) {
            throw GoogleIdTokenInvalid::create('Google ID token audience is invalid.');
        }
    }

    private function assertIssuer(stdClass $claims): void
    {
        $issuer = $claims->iss ?? null;
        if (!is_string($issuer) || !in_array($issuer, self::ALLOWED_ISSUERS, true)) {
            throw GoogleIdTokenInvalid::create('Google ID token issuer is invalid.');
        }
    }

    private function stringClaim(stdClass $claims, string $name): string
    {
        $value = $claims->{$name} ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw GoogleIdTokenInvalid::create(sprintf('Google ID token is missing claim "%s".', $name));
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

        throw GoogleIdTokenInvalid::create(sprintf('Google ID token is missing claim "%s".', $name));
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
