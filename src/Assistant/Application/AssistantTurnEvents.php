<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Application;

/**
 * Live progress of one assistant turn, for a caller that wants it as it happens.
 *
 * Shared for the length of a request, like {@see AssistantTurnTrace}. The
 * streaming endpoint listens; the plain endpoint does not, and a provider that
 * finds nobody listening keeps its ordinary request-and-wait behaviour. Events:
 *
 * - `text` `{text}`: a piece of the reply as the model writes it.
 * - `tool` `{name}`: a tool is about to run; any text so far was a preamble,
 *   not the answer.
 *
 * The final reply is not an event here. The endpoint sends it when the service
 * returns, so cards and choice buttons always come from the finished turn.
 */
final class AssistantTurnEvents
{
    /** @var (\Closure(string, array<string, mixed>): void)|null */
    private ?\Closure $listener = null;

    /** @param (callable(string, array<string, mixed>): void)|null $listener */
    public function listen(?callable $listener): void
    {
        $this->listener = $listener === null ? null : \Closure::fromCallable($listener);
    }

    public function isListening(): bool
    {
        return $this->listener !== null;
    }

    /** @param array<string, mixed> $data */
    public function emit(string $event, array $data): void
    {
        if ($this->listener !== null) {
            ($this->listener)($event, $data);
        }
    }
}
