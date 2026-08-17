<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Authentication;

use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Http\JsonHttpClient;
use TriCab\Core\Identity\Application\Exception\AppleTokenExchangeFailed;
use TriCab\Core\Identity\Contract\AppleAuthorizationCodeExchanger;

/**
 * Exchanges an Apple authorization code for an ID token.
 * Authorization codes are single-use at Apple; never log code, id_token, or client_secret.
 */
final class AppleAuthorizationCodeHttpExchanger implements AppleAuthorizationCodeExchanger
{
    private const TOKEN_URL = 'https://appleid.apple.com/auth/token';

    public function __construct(
        private readonly Settings $settings,
        private readonly AppleClientSecretGenerator $clientSecrets,
        private readonly JsonHttpClient $http,
    ) {
    }

    public function exchange(string $authorizationCode): string
    {
        if (!$this->settings->isAppleAuthConfigured()) {
            throw AppleTokenExchangeFailed::create('Apple Sign-In is not configured.');
        }

        $code = trim($authorizationCode);
        if ($code === '') {
            throw AppleTokenExchangeFailed::create('Apple authorization code is required.');
        }

        $clientSecret = $this->clientSecrets->generate();

        try {
            $response = $this->http->postForm(
                self::TOKEN_URL,
                [
                    'User-Agent' => 'tricab-api-apple-token/1.0',
                ],
                [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'client_id' => $this->settings->appleClientId,
                    'client_secret' => $clientSecret,
                    'redirect_uri' => $this->settings->appleRedirectUri,
                ],
            );
        } catch (\Throwable) {
            throw AppleTokenExchangeFailed::create('Apple authorization code exchange failed.');
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw AppleTokenExchangeFailed::create('Apple authorization code exchange failed.');
        }

        /** @var mixed $decoded */
        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw AppleTokenExchangeFailed::create('Apple authorization code exchange returned an invalid response.');
        }

        $idToken = $decoded['id_token'] ?? null;
        if (!is_string($idToken) || trim($idToken) === '' || substr_count($idToken, '.') !== 2) {
            throw AppleTokenExchangeFailed::create('Apple token response did not include an ID token.');
        }

        return trim($idToken);
    }
}
