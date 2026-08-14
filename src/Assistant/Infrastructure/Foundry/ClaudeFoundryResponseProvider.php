<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Infrastructure\Foundry;

use JsonException;
use TriCab\Api\Assistant\Application\AssistantGrounding;
use TriCab\Api\Assistant\Application\AssistantProviderResult;
use TriCab\Api\Assistant\Application\AssistantResponseProvider;
use TriCab\Api\Assistant\Application\GroundedProduct;
use TriCab\Api\Assistant\Infrastructure\Prompt\VersionedPrompt;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Http\JsonHttpClient;

/**
 * Microsoft Foundry adapter for Anthropic's Messages API.
 */
final class ClaudeFoundryResponseProvider implements AssistantResponseProvider
{
    private const ANTHROPIC_VERSION = '2023-06-01';
    private const MAX_PRODUCTS = 12;
    private const MAX_TEXT_LENGTH = 2_000;

    public function __construct(
        private readonly JsonHttpClient $http,
        private readonly FoundryAccessTokenProvider $accessTokens,
        private readonly string $endpoint,
        private readonly string $deployment,
        private readonly string $authMode,
        private readonly VersionedPrompt $prompt,
        private readonly string $apiKey = '',
    ) {
    }

    public function generate(AssistantGrounding $grounding): AssistantProviderResult
    {
        try {
            $response = $this->http->postJson(
                rtrim($this->endpoint, '/') . '/anthropic/v1/messages',
                $this->headers(),
                [
                    'model' => $this->deployment,
                    'max_tokens' => 1_200,
                    'temperature' => 0.1,
                    'system' => $this->prompt->content,
                    'messages' => [[
                        'role' => 'user',
                        'content' => $this->userContent($grounding),
                    ]],
                ],
            );
        } catch (FoundryProviderUnavailable $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new FoundryProviderUnavailable(
                'Foundry Messages request failed.',
                previous: $exception,
            );
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new FoundryProviderUnavailable('Foundry Messages request was rejected.');
        }

        $text = $this->responseText($response['body']);

        return new AssistantProviderResult(
            text: $text,
            provider: 'foundry-claude',
            instructionVersion: $this->prompt->id(),
        );
    }

    /**
     * @return array<string, string>
     */
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

    private function userContent(AssistantGrounding $grounding): string
    {
        $products = array_map(
            fn (GroundedProduct $product): array => $this->curatedProduct($product),
            array_slice($grounding->products, 0, self::MAX_PRODUCTS),
        );

        try {
            $json = json_encode([
                'customerQuestion' => self::limit($grounding->userMessage),
                'searchQuery' => self::limit($grounding->searchQuery),
                'regionCode' => $grounding->regionCode,
                'catalogueVisibility' => $grounding->catalogueVisibility,
                'queryInterpretation' => [
                    'canonicalIntent' => $grounding->canonicalIntent,
                    'attemptedSearchTerms' => array_map(
                        self::limit(...),
                        array_slice($grounding->searchTerms, 0, 6),
                    ),
                    'matchedSearchTerm' => $grounding->matchedSearchTerm,
                    'clarificationQuestion' => $grounding->clarificationQuestion,
                    'instructionVersion' => $grounding->queryInstructionVersion,
                ],
                'products' => $products,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new FoundryProviderUnavailable(
                'Catalogue grounding could not be encoded.',
                previous: $exception,
            );
        }

        return "AUTHORIZED_CATALOGUE_DATA\n" . $json;
    }

    /**
     * @return array<string, mixed>
     */
    private function curatedProduct(GroundedProduct $product): array
    {
        $data = $product->toGroundingArray();
        $data['summary'] = self::limitNullable($product->summary);
        $data['applications'] = array_map(
            self::limit(...),
            array_slice($product->applications, 0, 8),
        );
        $data['specifications'] = array_map(
            static fn (array $specification): array => [
                'name' => self::limit($specification['name']),
                'value' => self::limit($specification['value']),
            ],
            array_slice($product->specifications, 0, 12),
        );

        return $data;
    }

    private function responseText(string $body): string
    {
        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new FoundryProviderUnavailable(
                'Foundry Messages response was invalid.',
                previous: $exception,
            );
        }

        if (!is_array($decoded) || !isset($decoded['content']) || !is_array($decoded['content'])) {
            throw new FoundryProviderUnavailable('Foundry Messages response did not contain content.');
        }

        $parts = [];
        foreach ($decoded['content'] as $block) {
            if (
                is_array($block)
                && ($block['type'] ?? null) === 'text'
                && is_string($block['text'] ?? null)
                && trim($block['text']) !== ''
            ) {
                $parts[] = trim($block['text']);
            }
        }

        $text = trim(implode("\n\n", $parts));
        if ($text === '') {
            throw new FoundryProviderUnavailable('Foundry Messages response did not contain text.');
        }

        return $text;
    }

    private static function limit(string $value): string
    {
        if (strlen($value) <= self::MAX_TEXT_LENGTH) {
            return $value;
        }

        return substr($value, 0, self::MAX_TEXT_LENGTH);
    }

    private static function limitNullable(?string $value): ?string
    {
        return $value === null ? null : self::limit($value);
    }
}
