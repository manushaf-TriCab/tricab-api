<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Contract;

use TriCab\Core\Rfq\Sync\GatewayHeaderResult;
use TriCab\Core\Rfq\Sync\GatewayLineResult;
use TriCab\Core\Rfq\Sync\GatewayPdfResult;

/**
 * The only way a quote leaves for 4js (parity plan E7.3): the three requests the
 * source's `execute_quote_push` makes, as an interface.
 *
 * **Every test runs against a recording fake; nothing in the repository wires a
 * live adapter.** The source talks to 4js's `P008btch.php` with `QOTHDR`,
 * `QOTITEM` and `QOTPDF` query strings. That is a production write path whose
 * request and response formats are known here only from the source's code, not
 * from 4js, and it has no idempotency key and no verified quote lookup. So the
 * production binding is {@see \TriCab\Core\Rfq\Infrastructure\UnwiredRfqQuoteGateway},
 * which is not connected, and a sync answers 503 until a reviewed adapter is
 * written and bound by someone who may write to 4js.
 *
 * An adapter **must not throw to mean "no"**: a rejection is {@see GatewayHeaderResult::rejected()}
 * and an outcome it cannot know is {@see GatewayHeaderResult::unknown()}. Anything it does throw
 * is read as unknown, never as a rejection, because retrying a header that may have landed
 * is how one RFQ becomes two quotes.
 */
interface RfqQuoteGateway
{
    /** False when no adapter is wired; a sync then answers 503 and sends nothing. */
    public function isConnected(): bool;

    /** Why nothing is connected, in words for the rep. */
    public function unavailableNote(): string;

    /**
     * Create the quote (`ref` 0) or raise a new version of one (`ref` its number).
     *
     * @param array<string, scalar> $header the plan's header parameters, without the transport's own (`type`, `live`)
     */
    public function createHeader(array $header): GatewayHeaderResult;

    /**
     * One line, under the quote number the header returned.
     *
     * @param array{code: string, quantity: float, price: float, cprc: float, comment: string, unitsys: string} $line
     */
    public function addLine(string $refno, string $version, array $line): GatewayLineResult;

    /** Render the quote's PDF. Best effort; never changes the quote. */
    public function renderPdf(string $refno, string $refver): GatewayPdfResult;
}
