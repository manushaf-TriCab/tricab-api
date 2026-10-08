<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Migration;

/**
 * Turns the per-folder message ids Tri-AR stored into the immutable ids the platform keys on (question Q1).
 * Graph's `translateExchangeIds` is the real one. An id that can no longer be translated (the message was
 * moved out of reach or deleted) maps to null; a failure of the service as a whole throws, so the tool stops
 * instead of flagging every record unreachable because of an outage.
 */
interface ArMessageIdTranslator
{
    /**
     * @param list<string> $ids
     * @return array<string, string|null> every given id, with its immutable id or null
     * @throws ArMessageIdTranslationFailed
     */
    public function translate(string $mailbox, array $ids): array;
}
