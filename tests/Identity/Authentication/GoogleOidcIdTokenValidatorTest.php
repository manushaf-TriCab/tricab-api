<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Identity\Authentication;

use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Identity\Authentication\GoogleJwksProvider;
use TriCab\Api\Identity\Authentication\GoogleOidcIdTokenValidator;
use TriCab\Core\Identity\Application\Exception\GoogleIdTokenInvalid;

final class GoogleOidcIdTokenValidatorTest extends TestCase
{
    private const CLIENT_ID = 'tricab-google-client.apps.googleusercontent.com';

    public function test_validates_signed_google_id_token(): void
    {
        [$privateKey, $jwks] = $this->generateRsaKeyPair();
        $now = time();
        $token = JWT::encode([
            'iss' => 'https://accounts.google.com',
            'aud' => self::CLIENT_ID,
            'sub' => '108234567890',
            'email' => 'User@TriCab.com',
            'email_verified' => true,
            'iat' => $now,
            'exp' => $now + 3600,
        ], $privateKey, 'RS256', 'test-kid');

        $validator = new GoogleOidcIdTokenValidator(
            googleClientIds: self::CLIENT_ID,
            jwksClient: new FixedGoogleJwksClient($jwks),
        );

        $verified = $validator->validate($token);

        self::assertSame('108234567890', $verified->subjectId);
        self::assertSame('user@tricab.com', $verified->emailAddress);
        self::assertTrue($verified->emailVerified);
    }

    public function test_rejects_wrong_audience(): void
    {
        [$privateKey, $jwks] = $this->generateRsaKeyPair();
        $now = time();
        $token = JWT::encode([
            'iss' => 'https://accounts.google.com',
            'aud' => 'other-client.apps.googleusercontent.com',
            'sub' => '108234567890',
            'email' => 'user@tricab.com',
            'email_verified' => true,
            'iat' => $now,
            'exp' => $now + 3600,
        ], $privateKey, 'RS256', 'test-kid');

        $validator = new GoogleOidcIdTokenValidator(
            googleClientIds: self::CLIENT_ID,
            jwksClient: new FixedGoogleJwksClient($jwks),
        );

        $this->expectException(GoogleIdTokenInvalid::class);
        $validator->validate($token);
    }

    public function test_rejects_unverified_email_claim_as_false(): void
    {
        [$privateKey, $jwks] = $this->generateRsaKeyPair();
        $now = time();
        $token = JWT::encode([
            'iss' => 'https://accounts.google.com',
            'aud' => self::CLIENT_ID,
            'sub' => '108234567890',
            'email' => 'user@tricab.com',
            'email_verified' => false,
            'iat' => $now,
            'exp' => $now + 3600,
        ], $privateKey, 'RS256', 'test-kid');

        $validator = new GoogleOidcIdTokenValidator(
            googleClientIds: self::CLIENT_ID,
            jwksClient: new FixedGoogleJwksClient($jwks),
        );

        $verified = $validator->validate($token);
        self::assertFalse($verified->emailVerified);
    }

    /**
     * @return array{0: \OpenSSLAsymmetricKey, 1: array{keys: list<array<string, mixed>>}}
     */
    private function generateRsaKeyPair(): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($key);

        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertArrayHasKey('rsa', $details);

        /** @var array{n: string, e: string} $rsa */
        $rsa = $details['rsa'];

        $jwks = [
            'keys' => [[
                'kty' => 'RSA',
                'kid' => 'test-kid',
                'use' => 'sig',
                'alg' => 'RS256',
                'n' => rtrim(strtr(base64_encode($rsa['n']), '+/', '-_'), '='),
                'e' => rtrim(strtr(base64_encode($rsa['e']), '+/', '-_'), '='),
            ]],
        ];

        return [$key, $jwks];
    }
}

/**
 * @param array{keys: list<array<string, mixed>>} $jwks
 */
final class FixedGoogleJwksClient implements GoogleJwksProvider
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
