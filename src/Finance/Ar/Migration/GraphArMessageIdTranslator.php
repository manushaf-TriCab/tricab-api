<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ar\Migration;

use JsonException;
use Throwable;
use TriCab\Api\Infrastructure\Http\JsonHttpClient;
use TriCab\Core\Finance\Ar\Migration\ArMessageIdTranslationFailed;
use TriCab\Core\Finance\Ar\Migration\ArMessageIdTranslator;

/**
 * Graph's `translateExchangeIds` for the Tri-AR migration (question Q1): the per-folder REST ids the old app stored
 * become the immutable ids the platform keys on. Through the finance apps' own Graph registration, whose
 * credentials are passed in; this class reads no environment. The token and retry shape follow `GraphMailboxReader`.
 *
 * Graph refuses a whole batch (400 or 404) when one id in it can no longer be read, so a refused batch is halved
 * until the id that has no translation stands alone; that one maps to null. Anything else (no token, 401, 403, a
 * throttle or a server error that outlasts the retries, an answer that is not JSON) is a failure of the service and
 * throws. So does a run in which Graph refused ids and translated none at all: that is a wrong request or a wrong
 * mailbox, not 900 messages gone, and it must not flag every record unreachable.
 *
 * No message id, mailbox content or secret is put in an exception message.
 */
final class GraphArMessageIdTranslator implements ArMessageIdTranslator
{
    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

    private const GRAPH_SCOPE = 'https://graph.microsoft.com/.default';

    private const TOKEN_URL_TEMPLATE = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';

    private const SOURCE_ID_TYPE = 'restId';

    private const TARGET_ID_TYPE = 'restImmutableEntryId';

    private const BATCH_SIZE = 200;

    private const MAX_ATTEMPTS = 3;

    private const MAX_RETRY_DELAY_SECONDS = 5;

    private ?string $token = null;

    private int $tokenExpiresAt = 0;

    public function __construct(
        private readonly JsonHttpClient $http,
        private readonly string $tenantId,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly bool $pauseBetweenRetries = true,
    ) {
    }

    public function translate(string $mailbox, array $ids): array
    {
        $ids = array_values(array_unique($ids));
        $out = array_fill_keys($ids, null);
        $refused = 0;
        foreach (array_chunk($ids, self::BATCH_SIZE) as $batch) {
            $refused += $this->translateBatch($mailbox, $batch, $out);
        }
        if ($refused > 0 && array_filter($out, static fn (?string $id): bool => $id !== null) === []) {
            throw new ArMessageIdTranslationFailed('Microsoft Graph refused every message id and translated none. Check the mailbox and that the ids are the old app\'s.');
        }

        return $out;
    }

    /**
     * @param list<string> $batch
     * @param array<string, string|null> $out
     * @return int how many ids Graph refused
     */
    private function translateBatch(string $mailbox, array $batch, array &$out): int
    {
        $rows = $this->post($mailbox, $batch);
        if ($rows === null) {
            if (count($batch) === 1) {
                return 1;
            }
            $halves = array_chunk($batch, max(1, intdiv(count($batch) + 1, 2)));

            return $this->translateBatch($mailbox, $halves[0], $out) + $this->translateBatch($mailbox, $halves[1], $out);
        }
        foreach ($rows as $row) {
            $source = $row['sourceId'] ?? null;
            $target = $row['targetId'] ?? null;
            if (is_string($source) && array_key_exists($source, $out) && is_string($target) && $target !== '') {
                $out[$source] = $target;
            }
        }

        return 0;
    }

    /**
     * One `translateExchangeIds` call with the retry rules.
     *
     * @param list<string> $ids
     * @return list<array<string, mixed>>|null the answer's rows, or null when Graph refused these ids (400 or 404)
     */
    private function post(string $mailbox, array $ids): ?array
    {
        $url = self::GRAPH_BASE . '/users/' . rawurlencode($mailbox) . '/translateExchangeIds';
        $status = 0;
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $token = $this->accessToken();
            try {
                $response = $this->http->postJson($url, [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ], [
                    'inputIds' => $ids,
                    'sourceIdType' => self::SOURCE_ID_TYPE,
                    'targetIdType' => self::TARGET_ID_TYPE,
                ]);
            } catch (Throwable) {
                if ($attempt === self::MAX_ATTEMPTS) {
                    throw new ArMessageIdTranslationFailed('Microsoft Graph could not be reached.');
                }
                $this->pause($attempt);
                continue;
            }

            $status = $response['status'];
            if ($status >= 200 && $status <= 299) {
                return self::rows($response['body']);
            }
            if ($status === 400 || $status === 404) {
                return null;
            }
            if ($status === 401 || $status === 403) {
                $this->token = null;
                $this->tokenExpiresAt = 0;
                throw new ArMessageIdTranslationFailed('Microsoft Graph refused the translation with status ' . $status . '. translateExchangeIds is a user call, not a mail call: the finance Graph registration needs an admin-consented application permission such as User.ReadBasic.All, which Mail.Read does not cover.');
            }
            // 4xx other than 429 means the request is wrong; retrying only burns the throttle.
            if ($status >= 400 && $status < 500 && $status !== 429) {
                break;
            }
            if ($attempt < self::MAX_ATTEMPTS) {
                $this->pause($this->retryDelay($response, $attempt));
            }
        }

        throw new ArMessageIdTranslationFailed('Microsoft Graph refused the translation with status ' . $status . '.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(string $body): array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ArMessageIdTranslationFailed('Microsoft Graph answered with something that was not JSON.');
        }
        if (!is_array($decoded) || !is_array($decoded['value'] ?? null)) {
            throw new ArMessageIdTranslationFailed('Microsoft Graph answered without a list of translated ids.');
        }
        $rows = [];
        foreach ($decoded['value'] as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function accessToken(): string
    {
        $now = time();
        if ($this->token !== null && $this->token !== '' && $now < $this->tokenExpiresAt - 60) {
            return $this->token;
        }

        try {
            $response = $this->http->postForm(
                sprintf(self::TOKEN_URL_TEMPLATE, rawurlencode($this->tenantId)),
                [],
                [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope' => self::GRAPH_SCOPE,
                    'grant_type' => 'client_credentials',
                ],
            );
        } catch (Throwable) {
            throw new ArMessageIdTranslationFailed('The Microsoft sign-in endpoint could not be reached.');
        }
        if ($response['status'] < 200 || $response['status'] > 299) {
            throw new ArMessageIdTranslationFailed('The finance Graph registration was refused a token (status ' . $response['status'] . '). Check its tenant, client id and secret.');
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ArMessageIdTranslationFailed('The Microsoft token response was not JSON.');
        }
        if (!is_array($decoded) || !is_string($decoded['access_token'] ?? null) || $decoded['access_token'] === '') {
            throw new ArMessageIdTranslationFailed('The Microsoft token response had no access token.');
        }
        $token = $decoded['access_token'];
        $expires = $decoded['expires_in'] ?? 3600;
        $this->token = $token;
        $this->tokenExpiresAt = $now + max(60, is_numeric($expires) ? (int) $expires : 3600);

        return $token;
    }

    /**
     * @param array{status: int, body: string, headers?: array<string, string>} $response
     */
    private function retryDelay(array $response, int $attempt): int
    {
        $after = ($response['headers'] ?? [])['retry-after'] ?? null;
        if (is_string($after) && preg_match('/^\d+$/', trim($after)) === 1) {
            return max(1, min(self::MAX_RETRY_DELAY_SECONDS, (int) trim($after)));
        }

        return min(self::MAX_RETRY_DELAY_SECONDS, $attempt);
    }

    private function pause(int $seconds): void
    {
        if ($this->pauseBetweenRetries && $seconds > 0) {
            sleep(min(self::MAX_RETRY_DELAY_SECONDS, $seconds));
        }
    }
}
