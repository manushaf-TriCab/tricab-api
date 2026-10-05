<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Infrastructure;

use LogicException;
use TriCab\Core\Rfq\Contract\RfqQuoteGateway;
use TriCab\Core\Rfq\Sync\GatewayHeaderResult;
use TriCab\Core\Rfq\Sync\GatewayLineResult;
use TriCab\Core\Rfq\Sync\GatewayPdfResult;

/**
 * No 4js adapter is wired, in any environment, on purpose (parity plan E7.3,
 * owner decision: the old tc-web app stays the production quote owner during
 * the pilot). Every method refuses: the service checks {@see isConnected()}
 * first, so reaching one is a bug, and a loud one.
 */
final class UnwiredRfqQuoteGateway implements RfqQuoteGateway
{
    public function isConnected(): bool
    {
        return false;
    }

    public function unavailableNote(): string
    {
        return 'No 4js connection is set up on this server. The platform does not write quotes to 4js yet.';
    }

    public function createHeader(array $header): GatewayHeaderResult
    {
        throw new LogicException('No 4js adapter is wired; nothing may be sent.');
    }

    public function addLine(string $refno, string $version, array $line): GatewayLineResult
    {
        throw new LogicException('No 4js adapter is wired; nothing may be sent.');
    }

    public function renderPdf(string $refno, string $refver): GatewayPdfResult
    {
        throw new LogicException('No 4js adapter is wired; nothing may be sent.');
    }
}
