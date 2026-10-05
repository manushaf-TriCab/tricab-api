<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Rfq\Fixtures;

use Closure;
use Throwable;
use TriCab\Core\Rfq\Contract\RfqQuoteGateway;
use TriCab\Core\Rfq\Sync\GatewayHeaderResult;
use TriCab\Core\Rfq\Sync\GatewayLineResult;
use TriCab\Core\Rfq\Sync\GatewayPdfResult;

/**
 * The 4js adapter every test uses: it records each request and answers from a
 * script. It reaches nothing: no network, no 4js, no production database, and it
 * reads no credential. The real (unwired) adapter is `UnwiredRfqQuoteGateway`.
 */
final class RecordingQuoteGateway implements RfqQuoteGateway
{
    public bool $connected = true;

    /** @var list<array{type: string, args: array<string, mixed>}> */
    public array $calls = [];

    public GatewayHeaderResult $header;

    /** @var array<int, GatewayLineResult> line index => answer; other lines are accepted */
    public array $lineAnswers = [];

    public GatewayPdfResult $pdf;

    /** @var array<string, Throwable> 'header' | 'line:<index>' | 'pdf' => thrown instead of answering */
    public array $throws = [];

    /** @var Closure(string, array<string, mixed>): void|null runs as each request is made, before it is answered */
    public ?Closure $onCall = null;

    public function __construct()
    {
        $this->header = GatewayHeaderResult::accepted('721999', '1');
        $this->pdf = GatewayPdfResult::rendered('https://pdf.example.invalid/quote_721999.pdf');
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function unavailableNote(): string
    {
        return 'Test gateway is not connected.';
    }

    public function createHeader(array $header): GatewayHeaderResult
    {
        $this->record('header', ['header' => $header]);
        if (isset($this->throws['header'])) {
            throw $this->throws['header'];
        }

        return $this->header;
    }

    public function addLine(string $refno, string $version, array $line): GatewayLineResult
    {
        $index = count(array_filter($this->calls, static fn (array $c): bool => $c['type'] === 'line'));
        $this->record('line', ['refno' => $refno, 'version' => $version, 'line' => $line]);
        if (isset($this->throws['line:' . $index])) {
            throw $this->throws['line:' . $index];
        }

        return $this->lineAnswers[$index] ?? GatewayLineResult::accepted();
    }

    public function renderPdf(string $refno, string $refver): GatewayPdfResult
    {
        $this->record('pdf', ['refno' => $refno, 'refver' => $refver]);
        if (isset($this->throws['pdf'])) {
            throw $this->throws['pdf'];
        }

        return $this->pdf;
    }

    /** @return list<string> the request types in the order they were made */
    public function sequence(): array
    {
        return array_map(static fn (array $c): string => $c['type'], $this->calls);
    }

    /** @param array<string, mixed> $args */
    private function record(string $type, array $args): void
    {
        $this->calls[] = ['type' => $type, 'args' => $args];
        if ($this->onCall !== null) {
            ($this->onCall)($type, $args);
        }
    }
}
