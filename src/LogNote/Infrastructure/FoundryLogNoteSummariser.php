<?php

declare(strict_types=1);

namespace TriCab\Api\LogNote\Infrastructure;

use JsonException;
use Throwable;
use TriCab\Api\Assistant\Infrastructure\Foundry\FoundryAccessTokenProvider;
use TriCab\Api\Assistant\Infrastructure\Foundry\FoundryProviderUnavailable;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Http\JsonHttpClient;
use TriCab\Core\LogNote\Contract\LogNoteSummariser;
use TriCab\Core\LogNote\Domain\ValueObject\LogNoteBody;

final class FoundryLogNoteSummariser implements LogNoteSummariser
{
    private const ANTHROPIC_VERSION = '2023-06-01';
    private const SYSTEM_PROMPT = <<<'PROMPT'
You summarise TriCab sales Log Notes.

Rewrite the user's raw notes or dictation into a clear, professional customer-note body.
Keep facts, names, products, quantities, dates, follow-up actions, and commercial points.
Use concise paragraphs or short markdown bullets. Do not invent information.
Return only the summarised note body, with no preamble.
PROMPT;

    public function __construct(
        private readonly JsonHttpClient $http,
        private readonly FoundryAccessTokenProvider $accessTokens,
        private readonly string $endpoint,
        private readonly string $deployment,
        private readonly string $authMode,
        private readonly string $apiKey = '',
        private readonly string $effort = '',
    ) {
    }

    public function summarise(string $rawText): string
    {
        // max_tokens leaves room for thinking, which counts toward it on models that
        // think by default (Claude Sonnet 5.5); the summary itself stays short.
        $payload = [
            'model' => $this->deployment,
            'max_tokens' => 4_000,
            'system' => self::SYSTEM_PROMPT,
            'messages' => [
                ['role' => 'user', 'content' => mb_substr($rawText, 0, LogNoteBody::MAX_LENGTH)],
            ],
        ];
        if ($this->effort !== '') {
            $payload['output_config'] = ['effort' => $this->effort];
        }

        try {
            $response = $this->http->postJson(
                rtrim($this->endpoint, '/') . '/anthropic/v1/messages',
                $this->headers(),
                $payload,
            );
        } catch (FoundryProviderUnavailable $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new FoundryProviderUnavailable('Foundry Log Note summarise request failed.', previous: $exception);
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new FoundryProviderUnavailable(sprintf(
                'Foundry Log Note summarise was rejected (HTTP %d): %s',
                $response['status'],
                mb_substr(trim($response['body']), 0, 500),
            ));
        }

        try {
            $decoded = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new FoundryProviderUnavailable('Foundry Log Note summarise response was invalid.', previous: $exception);
        }

        if (!is_array($decoded) || !isset($decoded['content']) || !is_array($decoded['content'])) {
            throw new FoundryProviderUnavailable('Foundry Log Note summarise response did not contain content.');
        }

        $text = '';
        foreach ($decoded['content'] as $block) {
            if (!is_array($block) || ($block['type'] ?? null) !== 'text') {
                continue;
            }
            if (isset($block['text']) && is_string($block['text'])) {
                $text .= $block['text'];
            }
        }

        $summarised = trim($text);
        if ($summarised === '') {
            throw new FoundryProviderUnavailable('Foundry Log Note summarise returned an empty body.');
        }

        return $summarised;
    }

    public function providerName(): string
    {
        return 'foundry-claude';
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        $headers = ['anthropic-version' => self::ANTHROPIC_VERSION];
        if ($this->authMode === Settings::FOUNDRY_AUTH_MODE_API_KEY) {
            if (trim($this->apiKey) === '') {
                throw new FoundryProviderUnavailable('Foundry API key is not configured.');
            }
            $headers['x-api-key'] = $this->apiKey;

            return $headers;
        }
        if ($this->authMode === Settings::FOUNDRY_AUTH_MODE_MANAGED_IDENTITY) {
            $headers['Authorization'] = 'Bearer ' . $this->accessTokens->accessToken();

            return $headers;
        }

        throw new FoundryProviderUnavailable('Foundry authentication mode is not supported.');
    }
}
