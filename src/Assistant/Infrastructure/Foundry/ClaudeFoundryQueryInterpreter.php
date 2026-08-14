<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Infrastructure\Foundry;

use JsonException;
use Throwable;
use TriCab\Api\Assistant\Application\AssistantQueryInterpreter;
use TriCab\Api\Assistant\Application\AssistantSearchPlan;
use TriCab\Api\Assistant\Infrastructure\Prompt\VersionedPrompt;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Http\JsonHttpClient;

final readonly class ClaudeFoundryQueryInterpreter implements AssistantQueryInterpreter
{
    private const ANTHROPIC_VERSION = '2023-06-01';

    /** @var list<string> */
    private const CONFIRMED_SEARCH_TERMS = [
        'fire resistant', 'fr', 'radox', 'ws52w', 'x-125', 'r-125', 'hf-110',
        'vsd', 'vfd', 'emc', 'instrumentation', 'instrument', 'building wire',
        'lszh', 'ls0h', 'hffr', 'low smoke', 'halogen free',
    ];

    public function __construct(
        private JsonHttpClient $http,
        private FoundryAccessTokenProvider $accessTokens,
        private string $endpoint,
        private string $deployment,
        private string $authMode,
        private VersionedPrompt $prompt,
        private string $apiKey = '',
    ) {
    }

    public function interpret(
        string $message,
        ?string $explicitSearchQuery = null,
        ?string $regionCode = null,
    ): AssistantSearchPlan {
        $query = trim((string) ($explicitSearchQuery ?? $message));

        try {
            $userContent = json_encode([
                'customerQuery' => $query,
                'regionCode' => $regionCode,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $response = $this->http->postJson(
                rtrim($this->endpoint, '/') . '/anthropic/v1/messages',
                $this->headers(),
                [
                    'model' => $this->deployment,
                    'max_tokens' => 500,
                    'temperature' => 0.0,
                    'system' => $this->prompt->content,
                    'messages' => [[
                        'role' => 'user',
                        'content' => "CUSTOMER_QUERY\n" . $userContent,
                    ]],
                ],
            );
        } catch (FoundryProviderUnavailable $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new FoundryProviderUnavailable(
                'Foundry query interpretation request failed.',
                previous: $exception,
            );
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new FoundryProviderUnavailable('Foundry query interpretation request was rejected.');
        }

        return $this->searchPlan($query, $this->responseText($response['body']));
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

    private function responseText(string $body): string
    {
        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new FoundryProviderUnavailable(
                'Foundry query interpretation response was invalid.',
                previous: $exception,
            );
        }

        if (!is_array($decoded) || !isset($decoded['content']) || !is_array($decoded['content'])) {
            throw new FoundryProviderUnavailable('Foundry query interpretation response did not contain content.');
        }

        foreach ($decoded['content'] as $block) {
            if (
                is_array($block)
                && ($block['type'] ?? null) === 'text'
                && is_string($block['text'] ?? null)
                && trim($block['text']) !== ''
            ) {
                return trim($block['text']);
            }
        }

        throw new FoundryProviderUnavailable('Foundry query interpretation response did not contain text.');
    }

    private function searchPlan(string $query, string $text): AssistantSearchPlan
    {
        $json = trim($text);
        if (str_starts_with($json, '```')) {
            $json = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $json) ?? $json;
        }

        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new FoundryProviderUnavailable(
                'Foundry query interpretation did not return valid JSON.',
                previous: $exception,
            );
        }
        if (!is_array($decoded)) {
            throw new FoundryProviderUnavailable('Foundry query interpretation did not return an object.');
        }

        $confidence = $decoded['confidence'] ?? null;
        if (!is_numeric($confidence) || (float) $confidence < 0.5 || (float) $confidence > 1.0) {
            throw new FoundryProviderUnavailable('Foundry query interpretation confidence was insufficient.');
        }

        $terms = [];
        $rawTerms = $decoded['searchTerms'] ?? null;
        if (is_array($rawTerms)) {
            foreach ($rawTerms as $term) {
                if (!is_string($term)) {
                    continue;
                }
                $trimmed = trim($term);
                if (
                    mb_strlen($trimmed) < 2
                    || mb_strlen($trimmed) > 100
                    || preg_match('/[\r\n]/', $trimmed) === 1
                    || !$this->isGroundedTerm($trimmed, $query)
                ) {
                    continue;
                }
                $terms[mb_strtolower($trimmed)] = $trimmed;
                if (count($terms) >= 6) {
                    break;
                }
            }
        }
        if ($terms === []) {
            throw new FoundryProviderUnavailable('Foundry query interpretation returned no grounded search terms.');
        }

        return new AssistantSearchPlan(
            originalQuery: $query,
            canonicalIntent: $this->nullableText($decoded['canonicalIntent'] ?? null, 120),
            searchTerms: array_values($terms),
            clarificationQuestion: $this->nullableText($decoded['clarificationQuestion'] ?? null, 300),
            instructionVersion: $this->prompt->id(),
        );
    }

    private function isGroundedTerm(string $term, string $query): bool
    {
        $normalized = mb_strtolower($term);
        if (in_array($normalized, self::CONFIRMED_SEARCH_TERMS, true)) {
            return true;
        }

        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}.\/+\-]*/u', $term, $matches);
        $queryLower = mb_strtolower($query);
        $meaningfulTokens = [];
        foreach ($matches[0] as $token) {
            $normalizedToken = mb_strtolower(trim($token, '.,;:!?'));
            if (
                mb_strlen($normalizedToken) < 2
                || in_array($normalizedToken, ['cable', 'cables', 'product', 'products'], true)
            ) {
                continue;
            }
            $meaningfulTokens[] = $normalizedToken;
        }
        if ($meaningfulTokens === []) {
            return false;
        }
        foreach ($meaningfulTokens as $token) {
            if (!str_contains($queryLower, $token)) {
                return false;
            }
        }

        return true;
    }

    private function nullableText(mixed $value, int $maxLength): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '' || mb_strlen($trimmed) > $maxLength || preg_match('/[\r\n]/', $trimmed) === 1) {
            return null;
        }

        return $trimmed;
    }
}
