<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Response;

/** One `text/event-stream` frame: a named event with a JSON body. */
final class ServerSentEvent
{
    /** @param array<string, mixed> $data */
    public static function frame(string $event, array $data): string
    {
        // json_encode escapes newlines, so the body is always a single `data:` line.
        return 'event: ' . $event . "\n"
            . 'data: ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n\n";
    }

    /**
     * A comment frame the client ignores. Sent first, padded, so a proxy that
     * holds back the first few kilobytes of a response lets the stream through.
     */
    public static function opening(): string
    {
        return ':' . str_repeat(' ', 2048) . "\n\n";
    }
}
