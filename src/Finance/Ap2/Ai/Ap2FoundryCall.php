<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2\Ai;

use JsonException;
use Throwable;
use TriCab\Core\Finance\Ap2\Ai\Ap2AiUnavailable;
use TriCab\Core\Finance\Ap2\Ai\Ap2AiUsage;

/**
 * One metered use of the model, handed to the work inside {@see Ap2Foundry::call()}: every request it sends adds its
 * tokens, cost and time to {@see self::usage()}.
 */
final class Ap2FoundryCall
{
    private Ap2AiUsage $usage;

    public function __construct(
        private readonly Ap2Foundry $foundry,
        string $purpose,
        string $promptVersion,
        private readonly string $failurePrefix,
    ) {
        $this->usage = Ap2AiUsage::none($purpose, $foundry->deployment(), $promptVersion);
    }

    public function usage(): Ap2AiUsage
    {
        return $this->usage;
    }

    public function failed(string $what, ?Throwable $previous = null): Ap2AiUnavailable
    {
        return new Ap2AiUnavailable($this->failurePrefix . ': ' . $what, [], $previous);
    }

    /**
     * Sends one Messages request and answers the decoded message. Refused, truncated or non-2xx answers are failures.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function send(array $payload): array
    {
        $response = $this->foundry->post($payload, $this);
        $message = null;
        try {
            $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded) && !array_is_list($decoded)) {
                $message = [];
                foreach ($decoded as $key => $value) {
                    $message[(string) $key] = $value;
                }
            }
        } catch (JsonException) {
            $message = null;
        }
        $this->meter($message ?? [], $response['ms']);

        if ($response['status'] === 429 || $response['status'] === 529) {
            throw $this->failed('the model is busy; try again in a minute');
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw $this->failed('the model refused the request (HTTP ' . $response['status'] . ')');
        }
        if ($message === null) {
            throw $this->failed('the model answered with something that was not JSON');
        }
        $stop = $message['stop_reason'] ?? null;
        if ($stop === 'refusal') {
            throw $this->failed('the model declined this request');
        }
        if ($stop === 'max_tokens') {
            throw $this->failed('the answer was cut off before it was complete');
        }

        return $message;
    }

    /**
     * The JSON object of a structured-output answer: the first text block (thinking blocks come before it).
     *
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    public function structured(array $message): array
    {
        $text = self::text($message);
        if ($text === null) {
            throw $this->failed('the model did not return an answer');
        }
        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->failed('the model did not return the expected JSON');
        }
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw $this->failed('the model did not return the expected JSON');
        }
        $out = [];
        foreach ($decoded as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * All text blocks of a message joined, or null when there are none.
     *
     * @param array<string, mixed> $message
     */
    public static function text(array $message): ?string
    {
        $parts = [];
        foreach (is_array($message['content'] ?? null) ? $message['content'] : [] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            }
        }

        return $parts === [] ? null : implode("\n", $parts);
    }

    /** @param array<string, mixed> $message */
    private function meter(array $message, int $ms): void
    {
        $usage = is_array($message['usage'] ?? null) ? $message['usage'] : [];
        $int = static fn (string $key): int => is_int($usage[$key] ?? null) ? $usage[$key] : 0;
        $in = $int('input_tokens');
        $out = $int('output_tokens');
        $read = $int('cache_read_input_tokens');
        $write = $int('cache_creation_input_tokens');
        $model = is_string($message['model'] ?? null) ? $message['model'] : '';

        $this->usage = $this->usage->plus(new Ap2AiUsage(
            $this->usage->purpose,
            $model,
            $this->usage->promptVersion,
            $in,
            $out,
            $read,
            $write,
            $this->foundry->costOf($in, $out, $read, $write),
            $ms,
            1,
            true,
        ));
    }
}
