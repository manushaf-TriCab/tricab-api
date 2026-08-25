<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Identity\Email\DevelopmentGuestPasskeyEmailSender;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\GuestPasskeyEmailSender;
use TriCab\Core\Identity\Contract\GuestPasskeySetupTokenRepository;
use TriCab\Core\Identity\Contract\PasskeyChallengeRepository;
use TriCab\Core\Identity\Contract\UserPasskeyRepository;
use TriCab\Core\Identity\Contract\UserProvisioningRepository;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Entity\GuestPasskeySetupToken;
use TriCab\Core\Identity\Domain\Entity\PasskeyChallenge;
use TriCab\Core\Identity\Domain\Entity\UserPasskey;
use TriCab\Core\Identity\Domain\Service\EmailOtpExternalIdentityFactory;
use TriCab\Core\Identity\Domain\Service\PasskeyTokenHasher;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\PasskeyChallengePurpose;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;
use TriCab\Core\Identity\Domain\ValueObject\WebAuthnUserHandle;

final class PasskeyAuthEndpointsTest extends TestCase
{
    public function test_setup_link_returns_neutral_accepted_for_known_and_unknown_email(): void
    {
        $app = TestAppFactory::create();
        $this->seedEligiblePortalUser($app, 'guest@example.com');

        $known = $app->handle($this->jsonRequest('POST', '/api/v1/auth/guest/passkey/setup-link', [
            'email' => 'guest@example.com',
        ]));
        $unknown = $app->handle($this->jsonRequest('POST', '/api/v1/auth/guest/passkey/setup-link', [
            'email' => 'nobody@example.com',
        ]));

        foreach ([$known, $unknown] as $response) {
            $payload = TestAppFactory::json($response);
            self::assertSame(202, $response->getStatusCode());
            self::assertSame(['status' => 'accepted'], $payload['data']);
            $raw = (string) $response->getBody();
            self::assertStringNotContainsString('USER_EXISTS', $raw);
            self::assertStringNotContainsString('USER_NOT_FOUND', $raw);
            self::assertStringNotContainsString('PASSKEY_ALREADY_EXISTS', $raw);
        }

        $sender = $this->sender($app);
        self::assertSame('guest@example.com', $sender->lastMessage()['to'] ?? null);
    }

    public function test_setup_link_rejects_invalid_email(): void
    {
        $app = TestAppFactory::create();
        $response = $app->handle($this->jsonRequest('POST', '/api/v1/auth/guest/passkey/setup-link', [
            'email' => 'not-an-email',
        ]));
        $payload = TestAppFactory::json($response);
        self::assertSame(422, $response->getStatusCode());
        self::assertIsArray($payload['error']);
        self::assertSame('AUTH_EMAIL_INVALID', $this->errorCode($response));
    }

    public function test_setup_link_is_rate_limited_by_email(): void
    {
        $app = TestAppFactory::create();
        $this->seedEligiblePortalUser($app, 'guest@example.com');
        for ($i = 0; $i < 3; ++$i) {
            $ok = $app->handle($this->jsonRequest('POST', '/api/v1/auth/guest/passkey/setup-link', [
                'email' => 'guest@example.com',
            ]));
            self::assertSame(202, $ok->getStatusCode());
        }
        $limited = $app->handle($this->jsonRequest('POST', '/api/v1/auth/guest/passkey/setup-link', [
            'email' => 'guest@example.com',
        ]));
        self::assertSame(429, $limited->getStatusCode());
        self::assertSame('AUTH_RATE_LIMITED', $this->errorCode($limited));
    }

    public function test_setup_token_is_stored_hashed_not_raw(): void
    {
        $app = TestAppFactory::create();
        $raw = $this->requestSetupToken($app, 'guest@example.com');
        $tokens = $this->container($app)->get(GuestPasskeySetupTokenRepository::class);
        self::assertInstanceOf(GuestPasskeySetupTokenRepository::class, $tokens);
        $stored = $tokens->findByTokenHash(PasskeyTokenHasher::hash($raw));
        self::assertNotNull($stored);
        self::assertNotSame($raw, $stored->tokenHash());
        self::assertSame(PasskeyTokenHasher::hash($raw), $stored->tokenHash());
    }

    public function test_valid_setup_token_issues_registration_session(): void
    {
        $app = TestAppFactory::create();
        $raw = $this->requestSetupToken($app, 'guest@example.com');
        $response = $app->handle($this->jsonRequest('POST', '/api/v1/auth/guest/passkey/setup-link/verify', [
            'token' => $raw,
        ]));
        $payload = TestAppFactory::json($response);
        self::assertSame(200, $response->getStatusCode());
        $data = $this->responseData($payload);
        self::assertIsString($data['registrationSessionToken']);
        self::assertSame(300, $data['expiresIn']);
        self::assertArrayNotHasKey('accessToken', $data);
        self::assertStringContainsString('tricab_passkey_reg=', $response->getHeaderLine('Set-Cookie'));
    }

    public function test_malformed_setup_token_is_invalid(): void
    {
        $app = TestAppFactory::create();
        $response = $app->handle($this->jsonRequest('POST', '/api/v1/auth/guest/passkey/setup-link/verify', [
            'token' => 'not-a-token',
        ]));
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('AUTH_PASSKEY_SETUP_INVALID', $this->errorCode($response));
    }

    public function test_expired_and_consumed_setup_tokens_are_rejected(): void
    {
        $app = TestAppFactory::create();
        $this->seedEligiblePortalUser($app, 'guest@example.com');
        $user = $this->user($app, 'guest@example.com');
        $tokens = $this->container($app)->get(GuestPasskeySetupTokenRepository::class);
        self::assertInstanceOf(GuestPasskeySetupTokenRepository::class, $tokens);
        $uuids = new UuidV4Generator();
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $expiredRaw = PasskeyTokenHasher::randomToken();
        $tokens->save(new GuestPasskeySetupToken(
            null,
            $uuids->generate(),
            $user->id(),
            PasskeyTokenHasher::hash($expiredRaw),
            $now->sub(new DateInterval('PT1M')),
            null,
            $now->sub(new DateInterval('PT20M')),
            null,
        ));
        $expired = $app->handle($this->jsonRequest('POST', '/api/v1/auth/guest/passkey/setup-link/verify', [
            'token' => $expiredRaw,
        ]));
        self::assertSame(400, $expired->getStatusCode());
        self::assertSame('AUTH_PASSKEY_SETUP_EXPIRED', $this->errorCode($expired));

        $usedRaw = PasskeyTokenHasher::randomToken();
        $used = new GuestPasskeySetupToken(
            null,
            $uuids->generate(),
            $user->id(),
            PasskeyTokenHasher::hash($usedRaw),
            $now->add(new DateInterval('PT15M')),
            null,
            $now,
            null,
        );
        $used->consume($now);
        $tokens->save($used);
        $consumed = $app->handle($this->jsonRequest('POST', '/api/v1/auth/guest/passkey/setup-link/verify', [
            'token' => $usedRaw,
        ]));
        self::assertSame(400, $consumed->getStatusCode());
        self::assertSame('AUTH_PASSKEY_SETUP_USED', $this->errorCode($consumed));
    }

    public function test_unknown_setup_token_is_invalid(): void
    {
        $app = TestAppFactory::create();
        $response = $app->handle($this->jsonRequest('POST', '/api/v1/auth/guest/passkey/setup-link/verify', [
            'token' => str_repeat('ab', 32),
        ]));
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('AUTH_PASSKEY_SETUP_INVALID', $this->errorCode($response));
    }

    public function test_registration_options_require_session_and_complete_issues_passkey_jwt(): void
    {
        $app = TestAppFactory::create();
        [$session, $challengeId] = $this->startRegistration($app, 'guest@example.com');

        $missing = $app->handle($this->jsonRequest('POST', '/api/v1/auth/passkeys/registration/options', []));
        self::assertSame(400, $missing->getStatusCode());

        $complete = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/auth/passkeys/registration/complete',
            [
                'challengeId' => $challengeId,
                'credential' => [
                    'id' => 'cred-iphone-1',
                    'publicKey' => 'test-public-key',
                    'origin' => 'http://localhost:5173',
                    'rpId' => 'localhost',
                    'userVerification' => true,
                    'signCount' => 0,
                ],
            ],
            ['X-TriCab-Passkey-Registration-Session' => $session],
        ));
        $payload = TestAppFactory::json($complete);
        self::assertSame(200, $complete->getStatusCode());
        $data = $this->responseData($payload);
        self::assertIsString($data['accessToken']);
        self::assertSame('Bearer', $data['tokenType']);
        $user = $this->sessionUser($data);
        self::assertSame(['ACCOUNT.LOGIN'], $user['permissions']);
        self::assertArrayNotHasKey('userId', $user);

        $parts = explode('.', $data['accessToken']);
        self::assertCount(3, $parts);
        $b64 = strtr($parts[1], '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad !== 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $claims = json_decode((string) base64_decode($b64, true), true);
        self::assertIsArray($claims);
        self::assertSame('passkey', $claims['auth_provider']);

        $replay = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/auth/passkeys/registration/complete',
            [
                'challengeId' => $challengeId,
                'credential' => [
                    'id' => 'cred-iphone-2',
                    'origin' => 'http://localhost:5173',
                    'rpId' => 'localhost',
                ],
            ],
            ['X-TriCab-Passkey-Registration-Session' => $session],
        ));
        self::assertSame(401, $replay->getStatusCode());
    }

    public function test_registration_rejects_wrong_origin_rp_uv_and_duplicate_credential(): void
    {
        $app = TestAppFactory::create();
        [$session, $challengeId] = $this->startRegistration($app, 'guest@example.com');

        $wrongOrigin = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/auth/passkeys/registration/complete',
            [
                'challengeId' => $challengeId,
                'credential' => [
                    'id' => 'cred-1',
                    'origin' => 'https://evil.example',
                    'rpId' => 'localhost',
                ],
            ],
            ['X-TriCab-Passkey-Registration-Session' => $session],
        ));
        self::assertSame('AUTH_PASSKEY_ORIGIN_INVALID', $this->errorCode($wrongOrigin));

        [$session2, $challenge2] = $this->startRegistration($app, 'guest-b@example.com');
        $wrongRp = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/auth/passkeys/registration/complete',
            [
                'challengeId' => $challenge2,
                'credential' => [
                    'id' => 'cred-2',
                    'origin' => 'http://localhost:5173',
                    'rpId' => 'evil.com',
                ],
            ],
            ['X-TriCab-Passkey-Registration-Session' => $session2],
        ));
        self::assertSame('AUTH_PASSKEY_RP_INVALID', $this->errorCode($wrongRp));

        [$session3, $challenge3] = $this->startRegistration($app, 'guest-c@example.com');
        $noUv = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/auth/passkeys/registration/complete',
            [
                'challengeId' => $challenge3,
                'credential' => [
                    'id' => 'cred-3',
                    'origin' => 'http://localhost:5173',
                    'rpId' => 'localhost',
                    'userVerification' => false,
                ],
            ],
            ['X-TriCab-Passkey-Registration-Session' => $session3],
        ));
        self::assertSame('AUTH_PASSKEY_USER_VERIFICATION_REQUIRED', $this->errorCode($noUv));

        [$session4, $challenge4] = $this->startRegistration($app, 'guest-d@example.com');
        $ok = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/auth/passkeys/registration/complete',
            [
                'challengeId' => $challenge4,
                'credential' => [
                    'id' => 'shared-cred',
                    'origin' => 'http://localhost:5173',
                    'rpId' => 'localhost',
                ],
            ],
            ['X-TriCab-Passkey-Registration-Session' => $session4],
        ));
        self::assertSame(200, $ok->getStatusCode());
        [$session5, $challenge5] = $this->startRegistration($app, 'guest-e@example.com');
        $dup = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/auth/passkeys/registration/complete',
            [
                'challengeId' => $challenge5,
                'credential' => [
                    'id' => 'shared-cred',
                    'origin' => 'http://localhost:5173',
                    'rpId' => 'localhost',
                ],
            ],
            ['X-TriCab-Passkey-Registration-Session' => $session5],
        ));
        self::assertSame(409, $dup->getStatusCode());
        self::assertSame('AUTH_PASSKEY_CREDENTIAL_EXISTS', $this->errorCode($dup));
    }

    public function test_authentication_success_unknown_invalid_signature_replay_and_disabled_passkey(): void
    {
        $app = TestAppFactory::create();
        $this->registerPasskey($app, 'guest@example.com', 'cred-login-1');

        $options = $app->handle($this->jsonRequest('POST', '/api/v1/auth/passkeys/authentication/options', []));
        $challengeId = $this->responseData(TestAppFactory::json($options))['challengeId'];
        self::assertIsString($challengeId);

        $ok = $app->handle($this->jsonRequest('POST', '/api/v1/auth/passkeys/authentication/complete', [
            'challengeId' => $challengeId,
            'credential' => [
                'id' => 'cred-login-1',
                'signature' => 'valid',
                'origin' => 'http://localhost:5173',
                'rpId' => 'localhost',
                'signCount' => 0,
            ],
        ]));
        $payload = TestAppFactory::json($ok);
        self::assertSame(200, $ok->getStatusCode());
        self::assertSame(['ACCOUNT.LOGIN'], $this->sessionUser($this->responseData($payload))['permissions']);

        $replay = $app->handle($this->jsonRequest('POST', '/api/v1/auth/passkeys/authentication/complete', [
            'challengeId' => $challengeId,
            'credential' => [
                'id' => 'cred-login-1',
                'signature' => 'valid',
                'origin' => 'http://localhost:5173',
                'rpId' => 'localhost',
            ],
        ]));
        self::assertSame('AUTH_PASSKEY_CHALLENGE_INVALID', $this->errorCode($replay));

        $unknownOptions = TestAppFactory::json($app->handle($this->jsonRequest('POST', '/api/v1/auth/passkeys/authentication/options', [])));
        $unknown = $app->handle($this->jsonRequest('POST', '/api/v1/auth/passkeys/authentication/complete', [
            'challengeId' => $this->responseData($unknownOptions)['challengeId'],
            'credential' => [
                'id' => 'missing',
                'signature' => 'valid',
                'origin' => 'http://localhost:5173',
                'rpId' => 'localhost',
            ],
        ]));
        self::assertSame('AUTH_PASSKEY_UNKNOWN', $this->errorCode($unknown));

        $badSigOptions = TestAppFactory::json($app->handle($this->jsonRequest('POST', '/api/v1/auth/passkeys/authentication/options', [])));
        $badSig = $app->handle($this->jsonRequest('POST', '/api/v1/auth/passkeys/authentication/complete', [
            'challengeId' => $this->responseData($badSigOptions)['challengeId'],
            'credential' => [
                'id' => 'cred-login-1',
                'signature' => 'nope',
                'origin' => 'http://localhost:5173',
                'rpId' => 'localhost',
            ],
        ]));
        self::assertSame('AUTH_PASSKEY_SIGNATURE_INVALID', $this->errorCode($badSig));
    }

    public function test_expired_auth_challenge_and_disabled_user_and_revoked_passkey(): void
    {
        $app = TestAppFactory::create();
        $this->seedEligiblePortalUser($app, 'guest@example.com');
        $challenges = $this->container($app)->get(PasskeyChallengeRepository::class);
        self::assertInstanceOf(PasskeyChallengeRepository::class, $challenges);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expired = new PasskeyChallenge(
            null,
            (new UuidV4Generator())->generate(),
            null,
            null,
            PasskeyChallengePurpose::authentication(),
            random_bytes(32),
            $now->sub(new DateInterval('PT1M')),
            null,
            $now->sub(new DateInterval('PT10M')),
        );
        $challenges->save($expired);
        $response = $app->handle($this->jsonRequest('POST', '/api/v1/auth/passkeys/authentication/complete', [
            'challengeId' => $expired->publicId()->toString(),
            'credential' => [
                'id' => 'cred-login-1',
                'signature' => 'valid',
                'origin' => 'http://localhost:5173',
                'rpId' => 'localhost',
            ],
        ]));
        self::assertSame('AUTH_PASSKEY_CHALLENGE_INVALID', $this->errorCode($response));

        $this->registerPasskey($app, 'revoked@example.com', 'cred-revoked');
        $passkeys = $this->container($app)->get(UserPasskeyRepository::class);
        self::assertInstanceOf(UserPasskeyRepository::class, $passkeys);
        $stored = $passkeys->findByCredentialId('cred-revoked');
        self::assertNotNull($stored);
        $stored->revoke();
        $passkeys->save($stored);
        $opts = TestAppFactory::json($app->handle($this->jsonRequest('POST', '/api/v1/auth/passkeys/authentication/options', [])));
        $revoked = $app->handle($this->jsonRequest('POST', '/api/v1/auth/passkeys/authentication/complete', [
            'challengeId' => $this->responseData($opts)['challengeId'],
            'credential' => [
                'id' => 'cred-revoked',
                'signature' => 'valid',
                'origin' => 'http://localhost:5173',
                'rpId' => 'localhost',
            ],
        ]));
        self::assertSame('AUTH_PASSKEY_REVOKED', $this->errorCode($revoked));

        $disabled = $this->seedPortalUser($app, 'disabled@example.com', UserStatus::disabled());
        $passkeys->save(new UserPasskey(
            null,
            (new UuidV4Generator())->generate(),
            $disabled->id(),
            'cred-disabled',
            'test-public-key',
            0,
            [],
            null,
            'Passkey',
            $now,
            null,
            true,
        ));
        $passkeys->saveHandle($disabled->id(), WebAuthnUserHandle::generate());
        $disabledOpts = TestAppFactory::json($app->handle($this->jsonRequest('POST', '/api/v1/auth/passkeys/authentication/options', [])));
        $disabledAuth = $app->handle($this->jsonRequest('POST', '/api/v1/auth/passkeys/authentication/complete', [
            'challengeId' => $this->responseData($disabledOpts)['challengeId'],
            'credential' => [
                'id' => 'cred-disabled',
                'signature' => 'valid',
                'origin' => 'http://localhost:5173',
                'rpId' => 'localhost',
            ],
        ]));
        self::assertSame(403, $disabledAuth->getStatusCode());
    }

    public function test_user_can_register_multiple_passkeys(): void
    {
        $app = TestAppFactory::create();
        $this->registerPasskey($app, 'guest@example.com', 'cred-one');
        $this->registerPasskey($app, 'guest@example.com', 'cred-two');
        $passkeys = $this->container($app)->get(UserPasskeyRepository::class);
        self::assertInstanceOf(UserPasskeyRepository::class, $passkeys);
        $list = $passkeys->listActiveByUserId($this->user($app, 'guest@example.com')->id());
        self::assertCount(2, $list);
    }

    public function test_wrong_challenge_is_rejected(): void
    {
        $app = TestAppFactory::create();
        [$session, $challengeId] = $this->startRegistration($app, 'guest@example.com');
        $response = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/auth/passkeys/registration/complete',
            [
                'challengeId' => $challengeId,
                'credential' => [
                    'id' => 'cred-wrong-challenge',
                    'origin' => 'http://localhost:5173',
                    'rpId' => 'localhost',
                    'challenge' => 'not-the-challenge',
                ],
            ],
            ['X-TriCab-Passkey-Registration-Session' => $session],
        ));
        self::assertSame('AUTH_PASSKEY_CHALLENGE_INVALID', $this->errorCode($response));
    }

    public function test_replayed_setup_token_after_registration_is_rejected(): void
    {
        $app = TestAppFactory::create();
        $raw = $this->requestSetupToken($app, 'guest@example.com');
        $this->registerPasskey($app, 'guest@example.com', 'cred-replay', $raw);
        $replay = $app->handle($this->jsonRequest('POST', '/api/v1/auth/guest/passkey/setup-link/verify', [
            'token' => $raw,
        ]));
        self::assertSame(400, $replay->getStatusCode());
        self::assertSame('AUTH_PASSKEY_SETUP_USED', $this->errorCode($replay));
    }

    public function test_registration_options_ignore_request_host_header(): void
    {
        $app = TestAppFactory::create();
        [$session] = $this->startRegistration($app, 'guest@example.com');
        $options = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/auth/passkeys/registration/options',
            [],
            [
                'X-TriCab-Passkey-Registration-Session' => $session,
                'Host' => 'tricab-api-staging.azurewebsites.net',
            ],
        ));
        self::assertSame(200, $options->getStatusCode());
        $publicKey = $this->responseData(TestAppFactory::json($options))['publicKey'];
        self::assertIsArray($publicKey);
        self::assertIsArray($publicKey['rp']);
        self::assertSame('localhost', $publicKey['rp']['id']);
    }

    public function test_passkey_diagnostics_are_development_only_and_omit_secrets(): void
    {
        $dev = TestAppFactory::create();
        $ok = $dev->handle(TestAppFactory::request('GET', '/api/v1/dev/passkey-diagnostics'));
        self::assertSame(200, $ok->getStatusCode());
        $data = $this->responseData(TestAppFactory::json($ok));
        self::assertSame('FakePasskeyCeremony', $data['ceremony']);
        self::assertTrue($data['fakeCeremony']);
        self::assertSame('memory', $data['repository']);
        $raw = (string) $ok->getBody();
        self::assertStringNotContainsString('challenge', strtolower($raw));
        self::assertStringNotContainsString('accessToken', $raw);
        self::assertStringNotContainsString('cookie', strtolower($raw));

        $prod = TestAppFactory::create([
            'APP_ENV' => 'production',
            'ALLOW_IN_MEMORY_REPOSITORY_IN_PRODUCTION' => 'true',
            'USER_REPOSITORY' => 'memory',
            'GUEST_EMAIL_PROVIDER' => 'postmark',
            'POSTMARK_SERVER_TOKEN' => 'token',
            'POSTMARK_FROM_EMAIL' => 'noreply@tricab.com',
            'PASSKEY_ENABLED' => 'true',
            'PASSKEY_RP_ID' => 'tricab.com',
            'PASSKEY_ALLOWED_ORIGINS' => 'https://www.tricab.com,https://tricab.com',
            'PASSKEY_PUBLIC_APP_BASE_URL' => 'https://www.tricab.com',
            'PASSKEY_FAKE_CEREMONY' => 'false',
            'DEV_AUTH_ENABLED' => 'false',
        ]);
        $hidden = $prod->handle(TestAppFactory::request('GET', '/api/v1/dev/passkey-diagnostics'));
        self::assertSame(404, $hidden->getStatusCode());
    }

    public function test_owner_can_list_rename_and_revoke_passkeys(): void
    {
        $app = TestAppFactory::create();
        $token = $this->registerPasskey($app, 'guest@example.com', 'cred-manage-1');
        $list = $app->handle($this->jsonRequest(
            'GET',
            '/api/v1/auth/passkeys',
            [],
            ['Authorization' => 'Bearer ' . $token],
        ));
        self::assertSame(200, $list->getStatusCode());
        $items = $this->responseData(TestAppFactory::json($list))['items'];
        self::assertIsArray($items);
        self::assertCount(1, $items);
        self::assertIsString($items[0]['publicId']);
        $publicId = $items[0]['publicId'];

        $rename = $app->handle($this->jsonRequest(
            'PATCH',
            '/api/v1/auth/passkeys/' . $publicId,
            ['deviceLabel' => 'MacBook'],
            ['Authorization' => 'Bearer ' . $token],
        ));
        self::assertSame(200, $rename->getStatusCode());

        $revoked = $app->handle($this->jsonRequest(
            'DELETE',
            '/api/v1/auth/passkeys/' . $publicId,
            [],
            ['Authorization' => 'Bearer ' . $token],
        ));
        self::assertSame(200, $revoked->getStatusCode());

        $opts = TestAppFactory::json($app->handle($this->jsonRequest('POST', '/api/v1/auth/passkeys/authentication/options', [])));
        $auth = $app->handle($this->jsonRequest('POST', '/api/v1/auth/passkeys/authentication/complete', [
            'challengeId' => $this->responseData($opts)['challengeId'],
            'credential' => [
                'id' => 'cred-manage-1',
                'signature' => 'valid',
                'origin' => 'http://localhost:5173',
                'rpId' => 'localhost',
            ],
        ]));
        self::assertSame('AUTH_PASSKEY_REVOKED', $this->errorCode($auth));
    }

    public function test_cannot_rename_or_revoke_another_users_passkey(): void
    {
        $app = TestAppFactory::create();
        $ownerToken = $this->registerPasskey($app, 'owner@example.com', 'cred-owner');
        $otherToken = $this->registerPasskey($app, 'other@example.com', 'cred-other');
        $list = $app->handle($this->jsonRequest(
            'GET',
            '/api/v1/auth/passkeys',
            [],
            ['Authorization' => 'Bearer ' . $ownerToken],
        ));
        $publicId = $this->responseData(TestAppFactory::json($list))['items'][0]['publicId'];
        self::assertIsString($publicId);

        $rename = $app->handle($this->jsonRequest(
            'PATCH',
            '/api/v1/auth/passkeys/' . $publicId,
            ['deviceLabel' => 'Stolen'],
            ['Authorization' => 'Bearer ' . $otherToken],
        ));
        self::assertSame(401, $rename->getStatusCode());
        self::assertSame('AUTH_PASSKEY_UNKNOWN', $this->errorCode($rename));

        $revoke = $app->handle($this->jsonRequest(
            'DELETE',
            '/api/v1/auth/passkeys/' . $publicId,
            [],
            ['Authorization' => 'Bearer ' . $otherToken],
        ));
        self::assertSame(401, $revoke->getStatusCode());
        self::assertSame('AUTH_PASSKEY_UNKNOWN', $this->errorCode($revoke));
    }

    public function test_revoking_one_passkey_does_not_invalidate_another(): void
    {
        $app = TestAppFactory::create();
        $first = $this->registerPasskey($app, 'guest@example.com', 'cred-keep-1');
        $listed = $this->responseData(TestAppFactory::json($app->handle($this->jsonRequest(
            'GET',
            '/api/v1/auth/passkeys',
            [],
            ['Authorization' => 'Bearer ' . $first],
        ))))['items'];
        self::assertIsArray($listed);
        self::assertCount(1, $listed);
        $firstId = $listed[0]['publicId'];
        self::assertIsString($firstId);
        $this->registerPasskey($app, 'guest@example.com', 'cred-keep-2');
        $app->handle($this->jsonRequest(
            'DELETE',
            '/api/v1/auth/passkeys/' . $firstId,
            [],
            ['Authorization' => 'Bearer ' . $first],
        ));

        $opts = TestAppFactory::json($app->handle($this->jsonRequest('POST', '/api/v1/auth/passkeys/authentication/options', [])));
        $second = $app->handle($this->jsonRequest('POST', '/api/v1/auth/passkeys/authentication/complete', [
            'challengeId' => $this->responseData($opts)['challengeId'],
            'credential' => [
                'id' => 'cred-keep-2',
                'signature' => 'valid',
                'origin' => 'http://localhost:5173',
                'rpId' => 'localhost',
            ],
        ]));
        self::assertSame(200, $second->getStatusCode());
        $claims = $this->jwtClaims($this->responseData(TestAppFactory::json($second))['accessToken']);
        self::assertSame('passkey', $claims['auth_provider']);
    }


    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function responseData(array $payload): array
    {
        self::assertIsArray($payload['data']);
        /** @var array<string, mixed> $data */
        $data = $payload['data'];

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function sessionUser(array $data): array
    {
        self::assertIsArray($data['user']);
        /** @var array<string, mixed> $user */
        $user = $data['user'];

        return $user;
    }

    private function errorCode(ResponseInterface $response): string
    {
        $payload = TestAppFactory::json($response);
        self::assertIsArray($payload['error']);
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertIsString($error['code']);

        return $error['code'];
    }

    /**
     * @param App<ContainerInterface> $app
     * @return array{0: string, 1: string}
     */
    private function startRegistration(App $app, string $email): array
    {
        $raw = $this->requestSetupToken($app, $email);
        $verified = $this->responseData(TestAppFactory::json($app->handle($this->jsonRequest('POST', '/api/v1/auth/guest/passkey/setup-link/verify', [
            'token' => $raw,
        ]))));
        $session = $verified['registrationSessionToken'];
        self::assertIsString($session);
        $options = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/auth/passkeys/registration/options',
            [],
            ['X-TriCab-Passkey-Registration-Session' => $session],
        ));
        self::assertSame(200, $options->getStatusCode());
        $data = $this->responseData(TestAppFactory::json($options));
        self::assertIsArray($data['publicKey']);
        $publicKey = $data['publicKey'];
        $selection = $publicKey['authenticatorSelection'];
        self::assertIsArray($selection);
        self::assertSame('required', $selection['residentKey']);
        self::assertIsString($data['challengeId']);

        return [$session, $data['challengeId']];
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function registerPasskey(App $app, string $email, string $credentialId, ?string $rawToken = null): string
    {
        if ($rawToken === null) {
            [$session, $challengeId] = $this->startRegistration($app, $email);
        } else {
            $verified = $this->responseData(TestAppFactory::json($app->handle($this->jsonRequest('POST', '/api/v1/auth/guest/passkey/setup-link/verify', [
                'token' => $rawToken,
            ]))));
            $session = $verified['registrationSessionToken'];
            self::assertIsString($session);
            $options = $app->handle($this->jsonRequest(
                'POST',
                '/api/v1/auth/passkeys/registration/options',
                [],
                ['X-TriCab-Passkey-Registration-Session' => $session],
            ));
            self::assertSame(200, $options->getStatusCode());
            $challengeId = $this->responseData(TestAppFactory::json($options))['challengeId'];
            self::assertIsString($challengeId);
        }
        $complete = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/auth/passkeys/registration/complete',
            [
                'challengeId' => $challengeId,
                'credential' => [
                    'id' => $credentialId,
                    'origin' => 'http://localhost:5173',
                    'rpId' => 'localhost',
                    'userVerification' => true,
                    'signCount' => 0,
                ],
            ],
            ['X-TriCab-Passkey-Registration-Session' => $session],
        ));
        self::assertSame(200, $complete->getStatusCode());
        $accessToken = $this->responseData(TestAppFactory::json($complete))['accessToken'];
        self::assertIsString($accessToken);

        return $accessToken;
    }

    /**
     * @return array<string, mixed>
     */
    private function jwtClaims(mixed $accessToken): array
    {
        self::assertIsString($accessToken);
        $parts = explode('.', $accessToken);
        self::assertCount(3, $parts);
        $b64 = strtr($parts[1], '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad !== 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $claims = json_decode((string) base64_decode($b64, true), true);
        self::assertIsArray($claims);

        return $claims;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function requestSetupToken(App $app, string $email): string
    {
        $this->seedEligiblePortalUser($app, $email);
        $response = $app->handle($this->jsonRequest('POST', '/api/v1/auth/guest/passkey/setup-link', [
            'email' => $email,
        ]));
        self::assertSame(202, $response->getStatusCode());
        $last = $this->sender($app)->lastMessage();
        self::assertNotNull($last);
        $query = parse_url($last['setupUrl'], PHP_URL_QUERY);
        self::assertIsString($query);
        parse_str($query, $params);
        self::assertIsString($params['t'] ?? null);

        return (string) $params['t'];
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function sender(App $app): DevelopmentGuestPasskeyEmailSender
    {
        $sender = $this->container($app)->get(GuestPasskeyEmailSender::class);
        self::assertInstanceOf(DevelopmentGuestPasskeyEmailSender::class, $sender);

        return $sender;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function user(App $app, string $email): \TriCab\Core\Identity\Domain\Entity\User
    {
        $users = $this->container($app)->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $user = $users->findByEmail(EmailAddress::fromString($email));
        self::assertNotNull($user);

        return $user;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function seedEligiblePortalUser(App $app, string $email): void
    {
        $this->seedPortalUser($app, $email, UserStatus::active());
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function seedPortalUser(App $app, string $email, UserStatus $status): \TriCab\Core\Identity\Domain\Entity\User
    {
        $users = $this->container($app)->get(UserProvisioningRepository::class);
        self::assertInstanceOf(UserProvisioningRepository::class, $users);
        $emailAddress = EmailAddress::fromString($email);
        $existing = $users->findByEmail($emailAddress);
        if ($existing !== null) {
            return $existing;
        }

        return $users->register(
            emailAddress: $emailAddress,
            displayName: 'Portal ' . $emailAddress->toString(),
            status: $status,
            roles: [RoleCode::fromString('GUEST')],
            permissions: [PermissionCode::fromString('ACCOUNT.LOGIN')],
            identity: EmailOtpExternalIdentityFactory::forEmail(
                EmailAddress::fromString('seed-' . $emailAddress->toString()),
            ),
        );
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function container(App $app): ContainerInterface
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    private function jsonRequest(string $method, string $path, array $body, array $headers = []): ServerRequestInterface
    {
        $request = TestAppFactory::request($method, $path)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Origin', 'http://localhost:5173');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));

        return $request;
    }
}
