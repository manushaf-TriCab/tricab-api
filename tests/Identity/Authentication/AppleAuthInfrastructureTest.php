<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Identity\Authentication;

use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Config\Settings;
use TriCab\Api\Identity\Authentication\AppleAuthorizationChallengeService;
use TriCab\Api\Identity\Authentication\AppleAuthorizationCodeHttpExchanger;
use TriCab\Api\Identity\Authentication\AppleClientSecretGenerator;
use TriCab\Api\Tests\Doubles\FakeJsonHttpClient;
use TriCab\Core\Identity\Application\Exception\AppleAuthorizationChallengeInvalid;
use TriCab\Core\Identity\Application\Exception\AppleTokenExchangeFailed;

final class AppleAuthInfrastructureTest extends TestCase
{
    public function test_challenge_issue_and_validate_round_trip(): void
    {
        $service = new AppleAuthorizationChallengeService($this->appleSettings());
        $challenge = $service->issue();

        self::assertNotSame('', $challenge['state']);
        self::assertNotSame('', $challenge['nonce']);
        self::assertNotSame('', $challenge['expiresAtUtc']);

        $service->validate($challenge['state'], $challenge['nonce']);
        $this->expectException(AppleAuthorizationChallengeInvalid::class);
        $service->validate($challenge['state'], 'mismatched-nonce');
    }

    public function test_challenge_rejects_expired_state(): void
    {
        $settings = $this->appleSettings();
        $service = new AppleAuthorizationChallengeService($settings);
        $now = time() - 600;
        $state = JWT::encode([
            'iss' => $settings->guestJwtIssuer,
            'aud' => $settings->guestJwtAudience,
            'provider' => 'apple',
            'nonce_hash' => hash('sha256', 'nonce'),
            'jti' => 'jti-1',
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 60,
        ], $settings->guestJwtSecret, 'HS256');

        $this->expectException(AppleAuthorizationChallengeInvalid::class);
        $service->validate($state, 'nonce');
    }

    public function test_client_secret_generation_uses_expected_claims(): void
    {
        $settings = $this->appleSettings();
        $generator = new AppleClientSecretGenerator($settings);
        $secret = $generator->generate();

        $parts = explode('.', $secret);
        self::assertCount(3, $parts);

        $header = json_decode($this->b64($parts[0]), true, 512, JSON_THROW_ON_ERROR);
        $payload = json_decode($this->b64($parts[1]), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('ES256', $header['alg']);
        self::assertSame($settings->appleKeyId, $header['kid']);
        self::assertSame($settings->appleTeamId, $payload['iss']);
        self::assertSame($settings->appleClientId, $payload['sub']);
        self::assertSame('https://appleid.apple.com', $payload['aud']);
        self::assertIsInt($payload['iat']);
        self::assertIsInt($payload['exp']);
        self::assertGreaterThan($payload['iat'], $payload['exp']);
    }

    public function test_authorization_code_exchange_posts_expected_form_and_returns_id_token(): void
    {
        $settings = $this->appleSettings();
        $http = new FakeJsonHttpClient();
        $http->status = 200;
        $http->body = json_encode([
            'id_token' => 'aaa.bbb.ccc',
            'access_token' => 'opaque',
            'token_type' => 'Bearer',
        ], JSON_THROW_ON_ERROR);

        $exchanger = new AppleAuthorizationCodeHttpExchanger(
            $settings,
            new AppleClientSecretGenerator($settings),
            $http,
        );

        $idToken = $exchanger->exchange('single-use-code');

        self::assertSame('aaa.bbb.ccc', $idToken);
        self::assertCount(1, $http->calls);
        self::assertSame('form', $http->calls[0]['kind']);
        self::assertSame('https://appleid.apple.com/auth/token', $http->calls[0]['url']);
        self::assertSame('authorization_code', $http->calls[0]['fields']['grant_type'] ?? null);
        self::assertSame('single-use-code', $http->calls[0]['fields']['code'] ?? null);
        self::assertSame($settings->appleClientId, $http->calls[0]['fields']['client_id'] ?? null);
        self::assertSame($settings->appleRedirectUri, $http->calls[0]['fields']['redirect_uri'] ?? null);
        self::assertIsString($http->calls[0]['fields']['client_secret'] ?? null);
    }

    public function test_authorization_code_exchange_maps_http_failure(): void
    {
        $settings = $this->appleSettings();
        $http = new FakeJsonHttpClient();
        $http->status = 400;
        $http->body = '{"error":"invalid_grant"}';

        $exchanger = new AppleAuthorizationCodeHttpExchanger(
            $settings,
            new AppleClientSecretGenerator($settings),
            $http,
        );

        $this->expectException(AppleTokenExchangeFailed::class);
        $exchanger->exchange('bad-code');
    }

    private function appleSettings(): Settings
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        self::assertNotFalse($key);
        $pem = '';
        self::assertTrue(openssl_pkey_export($key, $pem));

        return Settings::fromEnvironment([
            'APP_ENV' => 'development',
            'GUEST_JWT_ISSUER' => 'https://api.tricab.local',
            'GUEST_JWT_AUDIENCE' => 'tricab-api',
            'GUEST_JWT_SECRET' => 'local-development-guest-jwt-secret-32b',
            'APPLE_CLIENT_ID' => 'com.tricab.services',
            'APPLE_TEAM_ID' => 'TEAMID1234',
            'APPLE_KEY_ID' => 'KEYID12345',
            'APPLE_PRIVATE_KEY' => str_replace("\n", "\\n", $pem),
            'APPLE_REDIRECT_URI' => 'https://app.tricab.local/auth/apple/callback',
        ]);
    }

    private function b64(string $value): string
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        self::assertNotFalse($decoded);

        return $decoded;
    }
}
