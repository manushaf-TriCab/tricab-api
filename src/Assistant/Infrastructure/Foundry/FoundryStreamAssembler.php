<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Infrastructure\Foundry;

use JsonException;

/**
 * Rebuilds a Messages API response from its stream events, in the same shape
 * the non-streaming call returns, so the tool loop after it does not need to
 * know which way the reply arrived.
 *
 * Blocks are kept exactly as sent (thinking blocks with their signatures
 * included), because they go back to the model on the next round trip.
 */
final class FoundryStreamAssembler
{
    /** @var array<int, array<string, mixed>> */
    private array $blocks = [];

    /** @var array<int, string> tool_use input as it streams, by block index */
    private array $partialJson = [];

    private ?string $stopReason = null;

    /** @var array<mixed>|null */
    private ?array $stopDetails = null;

    /** @var array<mixed> */
    private array $usage = [];

    private ?string $error = null;

    /** @param array<string, mixed> $data */
    public function apply(string $event, array $data): void
    {
        switch ($event) {
            case 'message_start':
                $message = $data['message'] ?? null;
                if (is_array($message) && is_array($message['usage'] ?? null)) {
                    $this->usage = $message['usage'];
                }
                break;
            case 'content_block_start':
                $index = $data['index'] ?? null;
                $block = $data['content_block'] ?? null;
                if (is_int($index) && is_array($block)) {
                    /** @var array<string, mixed> $block */
                    $this->blocks[$index] = $block;
                    if (($block['type'] ?? null) === 'tool_use') {
                        $this->partialJson[$index] = '';
                    }
                }
                break;
            case 'content_block_delta':
                $this->applyDelta($data);
                break;
            case 'content_block_stop':
                $index = $data['index'] ?? null;
                if (is_int($index) && isset($this->partialJson[$index])) {
                    $this->blocks[$index]['input'] = $this->toolInput($this->partialJson[$index]);
                    unset($this->partialJson[$index]);
                }
                break;
            case 'message_delta':
                $delta = $data['delta'] ?? null;
                if (is_array($delta)) {
                    if (is_string($delta['stop_reason'] ?? null)) {
                        $this->stopReason = $delta['stop_reason'];
                    }
                    if (is_array($delta['stop_details'] ?? null)) {
                        $this->stopDetails = $delta['stop_details'];
                    }
                }
                if (is_array($data['usage'] ?? null)) {
                    // message_delta carries the final output count; keep the input
                    // and cache counts message_start reported.
                    $this->usage = array_merge($this->usage, array_filter(
                        $data['usage'],
                        static fn (mixed $value): bool => $value !== null,
                    ));
                }
                break;
            case 'error':
                $error = $data['error'] ?? null;
                $this->error = is_array($error) && is_string($error['message'] ?? null)
                    ? (is_string($error['type'] ?? null) ? $error['type'] . ': ' : '') . $error['message']
                    : 'unknown stream error';
                break;
        }
    }

    public function error(): ?string
    {
        return $this->error;
    }

    /**
     * @return array{content: list<array<string, mixed>>, stop_reason: string|null, stop_details: array<mixed>|null, usage: array<mixed>}
     */
    public function message(): array
    {
        ksort($this->blocks);

        return [
            'content' => array_values($this->blocks),
            'stop_reason' => $this->stopReason,
            'stop_details' => $this->stopDetails,
            'usage' => $this->usage,
        ];
    }

    /** @param array<string, mixed> $data */
    private function applyDelta(array $data): void
    {
        $index = $data['index'] ?? null;
        $delta = $data['delta'] ?? null;
        if (!is_int($index) || !is_array($delta) || !isset($this->blocks[$index])) {
            return;
        }

        switch ($delta['type'] ?? null) {
            case 'text_delta':
                $this->append($index, 'text', $delta['text'] ?? null);
                break;
            case 'thinking_delta':
                $this->append($index, 'thinking', $delta['thinking'] ?? null);
                break;
            case 'signature_delta':
                $this->append($index, 'signature', $delta['signature'] ?? null);
                break;
            case 'input_json_delta':
                if (isset($this->partialJson[$index]) && is_string($delta['partial_json'] ?? null)) {
                    $this->partialJson[$index] .= $delta['partial_json'];
                }
                break;
        }
    }

    private function append(int $index, string $field, mixed $value): void
    {
        if (!is_string($value)) {
            return;
        }
        $current = $this->blocks[$index][$field] ?? '';
        $this->blocks[$index][$field] = (is_string($current) ? $current : '') . $value;
    }

    /**
     * Decoded the way the non-streaming response is (objects as arrays), so both
     * paths hand the loop the same thing. A call with no arguments streams no
     * JSON at all, and comes back as an empty array like `{}` does there.
     *
     * @return array<mixed>
     */
    private function toolInput(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
