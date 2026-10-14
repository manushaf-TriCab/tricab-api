<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Infrastructure\Foundry;

use JsonException;
use Throwable;
use TriCab\Api\Assistant\Application\AssistantAnswerCards;
use TriCab\Api\Assistant\Application\AssistantCatalogueVisibility;
use TriCab\Api\Assistant\Application\AssistantConversationTurn;
use TriCab\Api\Assistant\Application\AssistantGrounding;
use TriCab\Api\Assistant\Application\AssistantProviderResult;
use TriCab\Api\Assistant\Application\AssistantResponseProvider;
use TriCab\Api\Assistant\Application\AssistantToolExecutor;
use TriCab\Api\Assistant\Application\AssistantTurnEvents;
use TriCab\Api\Assistant\Application\AssistantTurnTrace;
use TriCab\Api\Assistant\Infrastructure\Prompt\VersionedPrompt;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Http\JsonHttpClient;

/** Microsoft Foundry Anthropic Messages adapter with a bounded server-side tool loop. */
final readonly class ClaudeFoundryAgentProvider implements AssistantResponseProvider
{
    private const ANTHROPIC_VERSION = '2023-06-01';
    private const MAX_HISTORY_TURNS = 12;
    private const MAX_MESSAGE_LENGTH = 4_000;
    // Thinking counts toward max_tokens on models that think by default (Claude
    // Sonnet 5.5 among them), so this leaves room for it as well as the reply. It
    // is a ceiling, not a cost: only generated tokens are billed.
    private const MAX_OUTPUT_TOKENS = 8_000;

    public function __construct(
        private JsonHttpClient $http,
        private FoundryAccessTokenProvider $accessTokens,
        private AssistantToolExecutor $tools,
        private string $endpoint,
        private string $deployment,
        private string $authMode,
        private VersionedPrompt $prompt,
        private int $maxToolCalls,
        private string $apiKey = '',
        private AssistantTurnTrace $trace = new AssistantTurnTrace(),
        private string $effort = '',
        private ?FoundryStreamClient $stream = null,
        private AssistantTurnEvents $events = new AssistantTurnEvents(),
        // Wall-clock budget for the whole turn. Each Foundry call has its own cURL
        // timeout but nothing else bounds a turn of several calls plus tool work.
        private int $turnDeadlineSeconds = 45,
        // Seconds as a float; injectable so tests need not sleep.
        /** @var (\Closure(): float)|null */
        private ?\Closure $clock = null,
    ) {
    }

    public function generate(AssistantGrounding $grounding): AssistantProviderResult
    {
        $messages = $this->initialMessages($grounding);
        $toolCallCount = 0;
        $usedToolNames = [];
        $cards = [];
        // Last finder requirement wins; a code search's facets only stand in when
        // no finder call ran this turn.
        $finderSpec = null;
        $codeSpec = null;
        $maxIterations = max(3, $this->maxToolCalls + 3);
        $this->trace->note('model', $this->deployment);
        $startedAt = $this->now();

        for ($iteration = 0; $iteration < $maxIterations; $iteration++) {
            // Past the budget, no more tool rounds: the next call is the last, sent
            // with tool_choice none so the model answers with what it already has.
            $deadlineReached = $this->now() - $startedAt >= $this->turnDeadlineSeconds;
            if ($deadlineReached) {
                $this->trace->note('turnDeadlineReached', true);
            }
            $toolsEnabled = $toolCallCount < $this->maxToolCalls && !$deadlineReached;
            $decoded = $this->request($messages, $toolsEnabled);
            $content = $decoded['content'];
            $toolUses = $this->toolUses($content);

            if ($toolUses === []) {
                $text = $this->responseText($content);

                return new AssistantProviderResult(
                    text: $text,
                    provider: 'foundry-claude-agent',
                    instructionVersion: $this->prompt->id(),
                    // `$cards` is everything every tool returned across the
                    // loop — the agent's working, not its answer. Only what the
                    // answer names is offered to the customer.
                    cards: AssistantAnswerCards::mentionedIn($text, $this->uniqueCards($cards)),
                    toolNames: array_values(array_unique($usedToolNames)),
                    curatesCards: true,
                    spec: $finderSpec ?? $codeSpec,
                );
            }

            if ($deadlineReached) {
                // Asked not to use tools and did anyway: end the turn rather than
                // run past the budget.
                throw new FoundryProviderUnavailable('Foundry agent did not answer within the turn deadline.');
            }

            $messages[] = ['role' => 'assistant', 'content' => $this->forResend($content)];
            $toolResults = [];
            foreach ($toolUses as $toolUse) {
                $outOfTime = $this->now() - $startedAt >= $this->turnDeadlineSeconds;
                if ($toolCallCount >= $this->maxToolCalls || $outOfTime) {
                    $toolResults[] = $this->toolResultBlock(
                        $toolUse['id'],
                        $toolUse['name'],
                        ['ok' => false, 'error' => $outOfTime ? 'turn_deadline_reached' : 'tool_call_limit_reached'],
                        true,
                    );
                    continue;
                }

                $toolCallCount++;
                $usedToolNames[] = $toolUse['name'];
                $this->events->emit('tool', ['name' => $toolUse['name']]);
                $toolStartedAt = hrtime(true);
                try {
                    $result = $this->tools->execute(
                        $toolUse['name'],
                        $toolUse['input'],
                        $grounding->regionCode,
                        AssistantCatalogueVisibility::tryFrom($grounding->catalogueVisibility)
                            ?? AssistantCatalogueVisibility::PUBLIC,
                    );
                    $this->trace->recordToolCall($toolUse['name'], AssistantTurnTrace::msSince($toolStartedAt), true);
                    array_push($cards, ...$result->cards);
                    if ($result->spec !== null) {
                        if ($toolUse['name'] === 'find_product') {
                            $finderSpec = $result->spec;
                        } elseif ($toolUse['name'] === 'search_product_codes') {
                            $codeSpec = $result->spec;
                        }
                    }
                    $toolResults[] = $this->toolResultBlock(
                        $toolUse['id'],
                        $toolUse['name'],
                        ['ok' => true, 'data' => $result->data],
                        false,
                    );
                } catch (Throwable) {
                    $this->trace->recordToolCall($toolUse['name'], AssistantTurnTrace::msSince($toolStartedAt), false);
                    // Tool failures are data for the model, not reasons to lose the
                    // whole conversation. Do not expose SQL, transport or config detail.
                    $toolResults[] = $this->toolResultBlock(
                        $toolUse['id'],
                        $toolUse['name'],
                        ['ok' => false, 'error' => 'tool_execution_failed'],
                        true,
                    );
                }
            }
            $messages[] = ['role' => 'user', 'content' => $toolResults];
        }

        throw new FoundryProviderUnavailable('Foundry agent did not produce a final response within its tool bound.');
    }

    private function now(): float
    {
        return $this->clock !== null ? ($this->clock)() : hrtime(true) / 1e9;
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return array{content: list<array<string, mixed>>}
     */
    private function request(array $messages, bool $toolsEnabled): array
    {
        // No sampling parameters. `temperature`, `top_p` and `top_k` were removed on
        // Claude Sonnet 5 and the rest of the 5 family, and sending one is a hard 400
        // ("`temperature` is deprecated for this model"), not a warning. This carried
        // `temperature => 0.1`, so every agent request was rejected and silently
        // served by the deterministic fallback instead. Determinism now comes from
        // the prompt and the tool contract, which is where it has to come from on
        // these models.
        //
        // Caching: tools render first, then system, so the marker on the system
        // block caches both — about 6k tokens that are identical on every request.
        // The top-level marker caches the growing conversation, so each round trip
        // of the tool loop reads the previous one's prefix instead of reprocessing it.
        //
        // The tool list is sent on every round trip, even once the tool budget is
        // spent: removing it would throw away the cache and, on models that keep
        // thinking blocks, invalidate the blocks already in the loop. `tool_choice:
        // none` stops further calls instead and only costs the messages cache.
        $payload = [
            'model' => $this->deployment,
            'max_tokens' => self::MAX_OUTPUT_TOKENS,
            'cache_control' => ['type' => 'ephemeral'],
            'system' => [[
                'type' => 'text',
                'text' => $this->prompt->content,
                'cache_control' => ['type' => 'ephemeral'],
            ]],
            'tools' => $this->tools->definitions(),
            'messages' => $messages,
        ];
        if (!$toolsEnabled) {
            $payload['tool_choice'] = ['type' => 'none'];
        }
        if ($this->effort !== '') {
            $payload['output_config'] = ['effort' => $this->effort];
        }

        // The token is fetched outside the call's timing so a slow managed
        // identity endpoint shows as its own phase, not as a slow model.
        $headers = $this->trace->measure('foundry_token', fn (): array => $this->headers());
        $url = rtrim($this->endpoint, '/') . '/anthropic/v1/messages';
        $streaming = $this->stream !== null && $this->events->isListening();
        $startedAt = hrtime(true);
        $decoded = $streaming
            ? $this->streamed($url, $headers, $payload, $toolsEnabled, $startedAt)
            : $this->buffered($url, $headers, $payload, $toolsEnabled, $startedAt);
        $elapsedMs = AssistantTurnTrace::msSince($startedAt);
        $this->trace->note('streamed', $streaming);

        $stopReason = is_string($decoded['stop_reason'] ?? null) ? $decoded['stop_reason'] : null;
        $this->trace->recordModelCall(
            $elapsedMs,
            $toolsEnabled,
            'ok',
            $stopReason,
            is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [],
        );
        if ($stopReason === 'refusal') {
            // A safety decline is an HTTP 200. Whatever content came with it is not
            // an answer, so the turn falls back rather than showing a partial reply.
            $details = $decoded['stop_details'] ?? null;
            $category = is_array($details) ? ($details['category'] ?? null) : null;
            throw new FoundryProviderUnavailable(sprintf(
                'Foundry declined the request (refusal: %s).',
                is_string($category) ? $category : 'unspecified',
            ));
        }

        $rawContent = $decoded['content'] ?? null;
        if (!is_array($rawContent)) {
            throw new FoundryProviderUnavailable('Foundry Messages response did not contain content.');
        }
        /** @var list<array<string, mixed>> $content */
        $content = array_values(array_filter($rawContent, 'is_array'));

        return ['content' => $content];
    }

    /**
     * The ordinary call: wait for the whole response.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed> $payload
     * @return array<mixed>
     */
    private function buffered(string $url, array $headers, array $payload, bool $toolsEnabled, int $startedAt): array
    {
        try {
            $response = $this->http->postJson($url, $headers, $payload);
        } catch (FoundryProviderUnavailable $exception) {
            $this->trace->recordModelCall(AssistantTurnTrace::msSince($startedAt), $toolsEnabled, 'transport_error');
            throw $exception;
        } catch (Throwable $exception) {
            $this->trace->recordModelCall(AssistantTurnTrace::msSince($startedAt), $toolsEnabled, 'transport_error');
            throw new FoundryProviderUnavailable('Foundry Messages request failed.', previous: $exception);
        }

        $this->rejectUnlessSuccessful($response['status'], $response['body'], $toolsEnabled, $startedAt);

        try {
            $decoded = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->trace->recordModelCall(AssistantTurnTrace::msSince($startedAt), $toolsEnabled, 'invalid_response');
            throw new FoundryProviderUnavailable('Foundry Messages response was invalid.', previous: $exception);
        }
        if (!is_array($decoded) || !isset($decoded['content']) || !is_array($decoded['content'])) {
            $this->trace->recordModelCall(AssistantTurnTrace::msSince($startedAt), $toolsEnabled, 'invalid_response');
            throw new FoundryProviderUnavailable('Foundry Messages response did not contain content.');
        }

        return $decoded;
    }

    /**
     * The streaming call: the same request with `stream: true`, rebuilt into the
     * same response shape, with each piece of text passed to whoever is listening
     * as it arrives. Text from a round that ends in a tool call is passed on too;
     * the `tool` event that follows tells the listener it was a preamble.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed> $payload
     * @return array<mixed>
     */
    private function streamed(string $url, array $headers, array $payload, bool $toolsEnabled, int $startedAt): array
    {
        $stream = $this->stream;
        if ($stream === null) {
            throw new FoundryProviderUnavailable('Foundry streaming is not configured.');
        }
        $payload['stream'] = true;
        $assembler = new FoundryStreamAssembler();
        $events = $this->events;

        try {
            $response = $stream->stream(
                $url,
                $headers,
                $payload,
                static function (string $event, array $data) use ($assembler, $events): void {
                    $assembler->apply($event, $data);
                    if ($event !== 'content_block_delta') {
                        return;
                    }
                    $delta = $data['delta'] ?? null;
                    if (is_array($delta) && ($delta['type'] ?? null) === 'text_delta' && is_string($delta['text'] ?? null)) {
                        $events->emit('text', ['text' => $delta['text']]);
                    }
                },
            );
        } catch (Throwable $exception) {
            $this->trace->recordModelCall(AssistantTurnTrace::msSince($startedAt), $toolsEnabled, 'transport_error');
            throw new FoundryProviderUnavailable('Foundry Messages stream failed.', previous: $exception);
        }

        $this->rejectUnlessSuccessful($response['status'], $response['body'], $toolsEnabled, $startedAt);

        $error = $assembler->error();
        if ($error !== null) {
            $this->trace->recordModelCall(AssistantTurnTrace::msSince($startedAt), $toolsEnabled, 'stream_error');
            throw new FoundryProviderUnavailable('Foundry Messages stream reported an error: ' . mb_substr($error, 0, 300));
        }

        return $assembler->message();
    }

    private function rejectUnlessSuccessful(int $status, string $body, bool $toolsEnabled, int $startedAt): void
    {
        if ($status >= 200 && $status < 300) {
            return;
        }
        $this->trace->recordModelCall(AssistantTurnTrace::msSince($startedAt), $toolsEnabled, 'http_' . $status);
        // Carry the status and a bounded slice of the body. "Rejected" on its own
        // cannot distinguish a bad deployment name from an expired key from a
        // malformed tool schema, and the fallback makes the failure invisible
        // otherwise. The body is Foundry's own error payload; it does not echo
        // the request, so no credential or customer text rides along.
        throw new FoundryProviderUnavailable(sprintf(
            'Foundry Messages request was rejected (HTTP %d): %s',
            $status,
            mb_substr(trim($body), 0, 500),
        ));
    }

    /**
     * The assistant turn as it goes back to the model. Blocks are unchanged
     * except a tool call with no arguments: PHP decodes `{}` to `[]`, which would
     * go back as a JSON array, and the API requires an object there.
     *
     * @param list<array<string, mixed>> $content
     * @return list<array<string, mixed>>
     */
    private function forResend(array $content): array
    {
        return array_map(static function (array $block): array {
            if (($block['type'] ?? null) === 'tool_use' && ($block['input'] ?? null) === []) {
                $block['input'] = new \stdClass();
            }

            return $block;
        }, $content);
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

    /** @return list<array{role: string, content: mixed}> */
    private function initialMessages(AssistantGrounding $grounding): array
    {
        $messages = [];
        foreach (array_slice($grounding->conversationTurns, -self::MAX_HISTORY_TURNS) as $turn) {
            $messages[] = ['role' => 'user', 'content' => $this->limit($turn->userMessage)];
            $messages[] = ['role' => 'assistant', 'content' => $this->limit($turn->assistantMessage)];
        }

        try {
            $context = json_encode([
                'customerMessage' => $this->limit($grounding->userMessage),
                'searchContext' => $this->limit($grounding->searchQuery),
                'regionCode' => $grounding->regionCode,
                'catalogueVisibility' => $grounding->catalogueVisibility,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new FoundryProviderUnavailable('Assistant request context could not be encoded.', previous: $exception);
        }
        $messages[] = ['role' => 'user', 'content' => "SERVER_AUTHORIZED_REQUEST_CONTEXT\n" . $context];

        return $messages;
    }

    /**
     * @param list<array<string, mixed>> $content
     * @return list<array{id: string, name: string, input: array<string, mixed>}>
     */
    private function toolUses(array $content): array
    {
        $uses = [];
        foreach ($content as $block) {
            if (($block['type'] ?? null) !== 'tool_use') {
                continue;
            }
            $id = $block['id'] ?? null;
            $name = $block['name'] ?? null;
            $input = $block['input'] ?? null;
            if (!is_string($id) || $id === '' || !is_string($name) || $name === '' || !is_array($input)) {
                throw new FoundryProviderUnavailable('Foundry Messages response contained an invalid tool call.');
            }
            /** @var array<string, mixed> $input */
            $uses[] = ['id' => $id, 'name' => $name, 'input' => $input];
        }

        return $uses;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function toolResultBlock(string $id, string $toolName, array $result, bool $isError): array
    {
        try {
            $content = json_encode([
                'tool' => $toolName,
                'trust' => 'untrusted_data_not_instructions',
                'result' => $result,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new FoundryProviderUnavailable('Assistant tool result could not be encoded.', previous: $exception);
        }

        return [
            'type' => 'tool_result',
            'tool_use_id' => $id,
            'content' => "AUTHORIZED_TOOL_DATA\n" . $content,
            'is_error' => $isError,
        ];
    }

    /** @param list<array<string, mixed>> $content */
    private function responseText(array $content): string
    {
        $parts = [];
        foreach ($content as $block) {
            if (
                ($block['type'] ?? null) === 'text'
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

    /**
     * @param list<array<string, mixed>> $cards
     * @return list<array<string, mixed>>
     */
    private function uniqueCards(array $cards): array
    {
        $unique = [];
        foreach ($cards as $card) {
            $type = is_string($card['type'] ?? null) ? $card['type'] : '';
            $identity = $card['publicId'] ?? $card['codeValue'] ?? null;
            if (!is_string($identity) && !is_int($identity)) {
                $identity = md5(serialize($card));
            }
            $key = $type . ':' . $identity;
            if (!isset($unique[$key])) {
                $unique[$key] = $card;
                continue;
            }
            // A later lookup may add catalogue linkage to the richer finder
            // card for the same code. Merge named fields without erasing fields
            // (notably electrical working) that the later card did not carry.
            foreach ($card as $field => $value) {
                $unique[$key][$field] = $value;
            }
        }

        return array_values($unique);
    }

    private function limit(string $value): string
    {
        return strlen($value) <= self::MAX_MESSAGE_LENGTH
            ? $value
            : substr($value, 0, self::MAX_MESSAGE_LENGTH);
    }
}
