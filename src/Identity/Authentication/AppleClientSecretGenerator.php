<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Authentication;

use DateTimeImmutable;
use DateTimeZone;
use Firebase\JWT\JWT;
use OpenSSLAsymmetricKey;
use TriCab\Api\Config\Settings;
use TriCab\Core\Identity\Application\Exception\AppleTokenExchangeFailed;

/**
 * Builds short-lived Apple client_secret JWTs (ES256) for token exchange.
 * Never log the private key or generated secret.
 */
final class AppleClientSecretGenerator
{
    public const AUDIENCE = 'https://appleid.apple.com';
    public const TTL_SECONDS = 300;
    public const ALGORITHM = 'ES256';

    public function __construct(
        private readonly Settings $settings,
    ) {
    }

    public function generate(?DateTimeImmutable $now = null): string
    {
        if (!$this->settings->isAppleAuthConfigured()) {
            throw AppleTokenExchangeFailed::create('Apple Sign-In is not configured.');
        }

        $nowUtc = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
        $expiresAt = $nowUtc->modify('+' . self::TTL_SECONDS . ' seconds');

        $privateKey = openssl_pkey_get_private($this->settings->resolvedApplePrivateKeyPem());
        if (!$privateKey instanceof OpenSSLAsymmetricKey) {
            throw AppleTokenExchangeFailed::create('Apple private key could not be loaded.');
        }

        return JWT::encode(
            [
                'iss' => $this->settings->appleTeamId,
                'iat' => $nowUtc->getTimestamp(),
                'exp' => $expiresAt->getTimestamp(),
                'aud' => self::AUDIENCE,
                'sub' => $this->settings->appleClientId,
            ],
            $privateKey,
            self::ALGORITHM,
            $this->settings->appleKeyId,
        );
    }
}
