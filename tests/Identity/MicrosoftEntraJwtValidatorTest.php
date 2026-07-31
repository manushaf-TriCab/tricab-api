<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Identity;

use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Config\Settings;
use TriCab\Api\Identity\Authentication\EntraJwksClient;
use TriCab\Api\Identity\Authentication\EntraJwksProvider;
use TriCab\Api\Identity\Authentication\EntraTokenValidationFailed;
use TriCab\Api\Identity\Authentication\EntraTokenValidationNotConfigured;
use TriCab\Api\Identity\Authentication\MicrosoftEntraJwtValidator;

final class MicrosoftEntraJwtValidatorTest extends TestCase
{
    private string $privateKey;

    private string $tenantId = 'f0ec33ca-6286-41fd-a659-e288f9934dda';

    private string $audience = 'api://11111111-1111-1111-1111-111111111111';

    protected function setUp(): void
    {
        $keypair = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($keypair);

        $exportedKey = '';
        $exported = openssl_pkey_export($keypair, $exportedKey);
        self::assertTrue($exported);
        if (!is_string($exportedKey) || $exportedKey === '') {
            self::fail('Unable to export RSA private key for JWT tests.');
        }
        $this->privateKey = $exportedKey;
    }

    public function test_unconfigured_settings_refuse_validation(): void
    {
        $validator = new MicrosoftEntraJwtValidator(
            Settings::fromEnvironment([
                'APP_ENV' => 'production',
                'ENTRA_TENANT_ID' => '',
                'ENTRA_AUDIENCE' => '',
            ]),
            $this->jwksClient(),
        );

        $this->expectException(EntraTokenValidationNotConfigured::class);
        $validator->validate('header.payload.signature');
    }

    public function test_valid_access_token_maps_oid_subject(): void
    {
        $token = $this->sign([
            'aud' => $this->audience,
            'iss' => 'https://login.microsoftonline.com/' . $this->tenantId . '/v2.0',
            'tid' => $this->tenantId,
            'oid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'scp' => 'access_as_user',
            'exp' => time() + 3600,
            'nbf' => time() - 60,
            'iat' => time() - 60,
        ]);

        $identity = $this->validator()->validate($token);

        self::assertSame('microsoft_entra', $identity->provider);
        self::assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $identity->subjectId);
        self::assertSame($this->tenantId, $identity->tenantId);
    }

    public function test_graph_audience_is_rejected(): void
    {
        $token = $this->sign([
            'aud' => 'https://graph.microsoft.com',
            'iss' => 'https://login.microsoftonline.com/' . $this->tenantId . '/v2.0',
            'tid' => $this->tenantId,
            'oid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'scp' => 'User.Read',
            'exp' => time() + 3600,
            'nbf' => time() - 60,
            'iat' => time() - 60,
        ]);

        $this->expectException(EntraTokenValidationFailed::class);
        $this->expectExceptionMessage('Microsoft Graph');
        $this->validator()->validate($token);
    }

    public function test_wrong_tenant_is_rejected(): void
    {
        $token = $this->sign([
            'aud' => $this->audience,
            'iss' => 'https://login.microsoftonline.com/' . $this->tenantId . '/v2.0',
            'tid' => '00000000-0000-0000-0000-000000000099',
            'oid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'scp' => 'access_as_user',
            'exp' => time() + 3600,
            'nbf' => time() - 60,
            'iat' => time() - 60,
        ]);

        $this->expectException(EntraTokenValidationFailed::class);
        $this->expectExceptionMessage('tenant');
        $this->validator()->validate($token);
    }

    public function test_missing_scope_returns_insufficient_scope(): void
    {
        $token = $this->sign([
            'aud' => $this->audience,
            'iss' => 'https://login.microsoftonline.com/' . $this->tenantId . '/v2.0',
            'tid' => $this->tenantId,
            'oid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'scp' => 'other_scope',
            'exp' => time() + 3600,
            'nbf' => time() - 60,
            'iat' => time() - 60,
        ]);

        try {
            $this->validator()->validate($token);
            self::fail('Expected insufficient scope failure.');
        } catch (EntraTokenValidationFailed $exception) {
            self::assertSame('INSUFFICIENT_SCOPE', $exception->errorCode);
            self::assertSame(403, $exception->httpStatus);
        }
    }

    public function test_expired_token_is_rejected(): void
    {
        $token = $this->sign([
            'aud' => $this->audience,
            'iss' => 'https://login.microsoftonline.com/' . $this->tenantId . '/v2.0',
            'tid' => $this->tenantId,
            'oid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'scp' => 'access_as_user',
            'exp' => time() - 10,
            'nbf' => time() - 120,
            'iat' => time() - 120,
        ]);

        $this->expectException(EntraTokenValidationFailed::class);
        $this->expectExceptionMessage('expired');
        $this->validator()->validate($token);
    }

    public function test_settings_require_development_env_for_dev_auth(): void
    {
        $production = Settings::fromEnvironment([
            'APP_ENV' => 'production',
            'DEV_AUTH_ENABLED' => 'true',
        ]);
        $development = Settings::fromEnvironment([
            'APP_ENV' => 'development',
            'DEV_AUTH_ENABLED' => 'true',
        ]);

        self::assertFalse($production->isDevelopmentAuthActive());
        self::assertTrue($development->isDevelopmentAuthActive());
    }

    public function test_microsoft_jwks_without_alg_are_accepted(): void
    {
        $token = $this->sign([
            'aud' => $this->audience,
            'iss' => 'https://login.microsoftonline.com/' . $this->tenantId . '/v2.0',
            'tid' => $this->tenantId,
            'oid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'scp' => 'access_as_user',
            'exp' => time() + 3600,
            'nbf' => time() - 60,
            'iat' => time() - 60,
        ]);

        // Simulate Microsoft Entra JWKS: keys have kid/kty/n/e but no alg.
        $identity = $this->validator(includeAlgInJwks: false)->validate($token);

        self::assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $identity->subjectId);
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function sign(array $claims): string
    {
        return JWT::encode($claims, $this->privateKey, 'RS256', 'test-key');
    }

    private function validator(bool $includeAlgInJwks = true): MicrosoftEntraJwtValidator
    {
        return new MicrosoftEntraJwtValidator(
            Settings::fromEnvironment([
                'APP_ENV' => 'production',
                'ENTRA_TENANT_ID' => $this->tenantId,
                'ENTRA_AUDIENCE' => $this->audience,
                'ENTRA_REQUIRED_SCOPE' => 'access_as_user',
                'ENTRA_SUBJECT_CLAIM' => 'oid',
            ]),
            $this->jwksClient($includeAlgInJwks),
        );
    }

    private function jwksClient(bool $includeAlg = true): EntraJwksProvider
    {
        $key = openssl_pkey_get_private($this->privateKey);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertIsArray($details['rsa']);

        /** @var array{n: string, e: string} $rsa */
        $rsa = $details['rsa'];

        $jwk = [
            'kty' => 'RSA',
            'kid' => 'test-key',
            'use' => 'sig',
            'n' => rtrim(strtr(base64_encode($rsa['n']), '+/', '-_'), '='),
            'e' => rtrim(strtr(base64_encode($rsa['e']), '+/', '-_'), '='),
        ];
        if ($includeAlg) {
            $jwk['alg'] = 'RS256';
        }

        $jwks = ['keys' => [$jwk]];

        return new class ($jwks) implements EntraJwksProvider {
            /** @param array{keys: list<array<string, mixed>>} $jwks */
            public function __construct(private readonly array $jwks)
            {
            }

            public function fetch(string $tenantId): array
            {
                unset($tenantId);

                return $this->jwks;
            }
        };
    }
}
