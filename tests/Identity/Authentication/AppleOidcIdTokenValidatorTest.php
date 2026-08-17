<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Identity\Authentication;

use Firebase\JWT\JWT;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Config\Settings;
use TriCab\Api\Identity\Authentication\AppleJwksProvider;
use TriCab\Api\Identity\Authentication\AppleOidcIdTokenValidator;
use TriCab\Core\Identity\Application\Exception\AppleIdTokenInvalid;

final class AppleOidcIdTokenValidatorTest extends TestCase
{
    private const CLIENT_ID = 'com.tricab.services';
    private const RAW_NONCE = 'frontend-raw-nonce-value';

    public function test_validates_signed_apple_id_token(): void
    {
        [$privateKey, $jwks] = $this->generateEcKeyPair();
        $validator = $this->validator($jwks);
        $token = $this->encodeToken($privateKey, [
            'iss' => 'https://appleid.apple.com',
            'aud' => self::CLIENT_ID,
            'sub' => 'apple-sub-1',
            'email' => 'User@TriCab.com',
            'email_verified' => 'true',
            'nonce' => hash('sha256', self::RAW_NONCE),
        ]);

        $verified = $validator->validate($token, self::RAW_NONCE);

        self::assertSame('apple-sub-1', $verified->subjectId);
        self::assertSame('user@tricab.com', $verified->emailAddress);
        self::assertTrue($verified->emailVerified);
    }

    public function test_rejects_wrong_audience(): void
    {
        [$privateKey, $jwks] = $this->generateEcKeyPair();
        $token = $this->encodeToken($privateKey, [
            'iss' => 'https://appleid.apple.com',
            'aud' => 'other.client.id',
            'sub' => 'apple-sub-1',
            'email' => 'user@tricab.com',
            'email_verified' => true,
            'nonce' => hash('sha256', self::RAW_NONCE),
        ]);

        $this->expectException(AppleIdTokenInvalid::class);
        $this->validator($jwks)->validate($token, self::RAW_NONCE);
    }

    public function test_rejects_wrong_issuer(): void
    {
        [$privateKey, $jwks] = $this->generateEcKeyPair();
        $token = $this->encodeToken($privateKey, [
            'iss' => 'https://example.com',
            'aud' => self::CLIENT_ID,
            'sub' => 'apple-sub-1',
            'email' => 'user@tricab.com',
            'email_verified' => true,
            'nonce' => hash('sha256', self::RAW_NONCE),
        ]);

        $this->expectException(AppleIdTokenInvalid::class);
        $this->validator($jwks)->validate($token, self::RAW_NONCE);
    }

    public function test_rejects_expired_token(): void
    {
        [$privateKey, $jwks] = $this->generateEcKeyPair();
        $now = time();
        $token = JWT::encode([
            'iss' => 'https://appleid.apple.com',
            'aud' => self::CLIENT_ID,
            'sub' => 'apple-sub-1',
            'email' => 'user@tricab.com',
            'email_verified' => true,
            'nonce' => hash('sha256', self::RAW_NONCE),
            'iat' => $now - 7200,
            'exp' => $now - 3600,
        ], $privateKey, 'ES256', 'test-kid');

        $this->expectException(AppleIdTokenInvalid::class);
        $this->validator($jwks)->validate($token, self::RAW_NONCE);
    }

    public function test_rejects_nonce_mismatch(): void
    {
        [$privateKey, $jwks] = $this->generateEcKeyPair();
        $token = $this->encodeToken($privateKey, [
            'iss' => 'https://appleid.apple.com',
            'aud' => self::CLIENT_ID,
            'sub' => 'apple-sub-1',
            'email' => 'user@tricab.com',
            'email_verified' => true,
            'nonce' => hash('sha256', 'different-nonce'),
        ]);

        $this->expectException(AppleIdTokenInvalid::class);
        $this->validator($jwks)->validate($token, self::RAW_NONCE);
    }

    public function test_rejects_missing_email(): void
    {
        [$privateKey, $jwks] = $this->generateEcKeyPair();
        $token = $this->encodeToken($privateKey, [
            'iss' => 'https://appleid.apple.com',
            'aud' => self::CLIENT_ID,
            'sub' => 'apple-sub-1',
            'email_verified' => true,
            'nonce' => hash('sha256', self::RAW_NONCE),
        ]);

        $this->expectException(AppleIdTokenInvalid::class);
        $this->validator($jwks)->validate($token, self::RAW_NONCE);
    }

    public function test_rejects_unverified_email(): void
    {
        [$privateKey, $jwks] = $this->generateEcKeyPair();
        $token = $this->encodeToken($privateKey, [
            'iss' => 'https://appleid.apple.com',
            'aud' => self::CLIENT_ID,
            'sub' => 'apple-sub-1',
            'email' => 'user@tricab.com',
            'email_verified' => false,
            'nonce' => hash('sha256', self::RAW_NONCE),
        ]);

        $this->expectException(AppleIdTokenInvalid::class);
        $this->validator($jwks)->validate($token, self::RAW_NONCE);
    }

    public function test_rejects_invalid_signature(): void
    {
        [$privateKey, $jwks] = $this->generateEcKeyPair();
        [$otherKey] = $this->generateEcKeyPair();
        $token = $this->encodeToken($otherKey, [
            'iss' => 'https://appleid.apple.com',
            'aud' => self::CLIENT_ID,
            'sub' => 'apple-sub-1',
            'email' => 'user@tricab.com',
            'email_verified' => true,
            'nonce' => hash('sha256', self::RAW_NONCE),
        ]);

        $this->expectException(AppleIdTokenInvalid::class);
        $this->validator($jwks)->validate($token, self::RAW_NONCE);
    }

    /**
     * @param array{keys: list<array<string, mixed>>} $jwks
     */
    private function validator(array $jwks): AppleOidcIdTokenValidator
    {
        return new AppleOidcIdTokenValidator(
            settings: $this->appleSettings(),
            jwksClient: new FixedAppleJwksClient($jwks),
            clockSkewSeconds: 0,
        );
    }

    private function appleSettings(): Settings
    {
        [$pem] = $this->generatePemPair();

        return Settings::fromEnvironment([
            'APP_ENV' => 'development',
            'GUEST_JWT_ISSUER' => 'https://api.tricab.local',
            'GUEST_JWT_AUDIENCE' => 'tricab-api',
            'GUEST_JWT_SECRET' => 'local-development-guest-jwt-secret-32b',
            'APPLE_CLIENT_ID' => self::CLIENT_ID,
            'APPLE_TEAM_ID' => 'TEAMID1234',
            'APPLE_KEY_ID' => 'KEYID12345',
            'APPLE_PRIVATE_KEY' => $pem,
            'APPLE_REDIRECT_URI' => 'https://app.tricab.local/auth/apple/callback',
        ]);
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function encodeToken(OpenSSLAsymmetricKey $privateKey, array $claims): string
    {
        $now = time();

        return JWT::encode(
            array_merge([
                'iat' => $now,
                'exp' => $now + 3600,
            ], $claims),
            $privateKey,
            'ES256',
            'test-kid',
        );
    }

    /**
     * @return array{0: OpenSSLAsymmetricKey, 1: array{keys: list<array<string, mixed>>}}
     */
    private function generateEcKeyPair(): array
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        self::assertNotFalse($key);

        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertArrayHasKey('ec', $details);

        /** @var array{x: string, y: string} $ec */
        $ec = $details['ec'];
        $x = str_pad($ec['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);

        $jwks = [
            'keys' => [[
                'kty' => 'EC',
                'kid' => 'test-kid',
                'use' => 'sig',
                'alg' => 'ES256',
                'crv' => 'P-256',
                'x' => rtrim(strtr(base64_encode($x), '+/', '-_'), '='),
                'y' => rtrim(strtr(base64_encode($y), '+/', '-_'), '='),
            ]],
        ];

        return [$key, $jwks];
    }

    /**
     * @return array{0: string, 1: OpenSSLAsymmetricKey}
     */
    private function generatePemPair(): array
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        self::assertNotFalse($key);
        $exported = '';
        self::assertTrue(openssl_pkey_export($key, $exported));

        return [str_replace("\n", "\\n", $exported), $key];
    }
}

/**
 * @param array{keys: list<array<string, mixed>>} $jwks
 */
final class FixedAppleJwksClient implements AppleJwksProvider
{
    /**
     * @param array{keys: list<array<string, mixed>>} $jwks
     */
    public function __construct(
        private readonly array $jwks,
    ) {
    }

    public function fetch(): array
    {
        return $this->jwks;
    }
}
