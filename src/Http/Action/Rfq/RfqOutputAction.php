<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Rfq;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Rfq\Application\DTO\RfqExportView;
use TriCab\Core\Rfq\Application\Service\ResolveRfqActorService;
use TriCab\Core\Rfq\Application\Service\RfqReviewService;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;
use TriCab\Core\Rfq\Domain\Exception\RfqActionForbidden;
use TriCab\Core\Rfq\Domain\Exception\RfqStoreUnavailable;
use TriCab\Core\Rfq\Parser\CorrelationId;
use TriCab\Core\Rfq\Parser\ParserContract;
use TriCab\Core\Rfq\Parser\RfqParserFailure;
use TriCab\Core\Rfq\Storage\QuoteHeaderEdit;

/**
 * The editor's outputs (parity plan E7.1): the Product Summary panel and the
 * two CSV exports, all built on the server from the lines a save would write,
 * so the browser never sends rows to be trusted and a file matches what would
 * be saved. Nothing is stored.
 *
 * The body is the readiness one, `{parseToken?, header, lines}` for an unsaved
 * match and `{header?, lines?}` for a draft (left out, as saved), plus
 * `includePrices` (default false) on the two files.
 *
 *  - `POST /api/v1/rfqs/summary`, `POST /api/v1/rfqs/{publicId}/summary`:
 *    `rfq.product-summary.v1` JSON. No cost, margin or price in it.
 *  - `POST /api/v1/rfqs/exports/quote-lines`, `.../{publicId}/exports/quote-lines`:
 *    `quote-lines.csv`, one row per line.
 *  - `POST /api/v1/rfqs/exports/summary-lines`, `.../{publicId}/exports/summary-lines`:
 *    `summary-quote-lines.csv`, one row per product that can be priced.
 *
 * The unsaved form is gated on `RFQ.DRAFT.CREATE`, a draft's on
 * `RFQ.DRAFT.READ`. `includePrices` (the two money columns) is refused to
 * anyone without `RFQ.PRICING.VIEW`; cost and margin are never in a file.
 * The route names the output in its `output` argument.
 */
final class RfqOutputAction
{
    use RfqWriteFailures;

    public const SUMMARY = 'summary';

    public function __construct(
        private readonly ResolveRfqActorService $actors,
        private readonly RfqReviewService $review,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $user = $request->getAttribute(AuthenticatedUserAttribute::KEY);
        if (!$user instanceof AuthenticatedUser) {
            return $this->responder->error('UNAUTHENTICATED', 'Authentication is required.', 401);
        }
        $output = $args['output'] ?? self::SUMMARY;
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return $this->responder->error('RFQ_INPUT_INVALID', 'Send the lines as a JSON object.', 422);
        }
        $publicId = $args['publicId'] ?? null;
        $lines = null;
        if ($publicId === null || array_key_exists('lines', $body)) {
            $lines = self::lineDecisions($body['lines'] ?? null);
            if (is_string($lines)) {
                return $this->responder->error('RFQ_INPUT_INVALID', $lines, 422);
            }
        }
        $includePrices = $body['includePrices'] ?? false;
        if (!is_bool($includePrices)) {
            return $this->responder->error('RFQ_INPUT_INVALID', 'includePrices must be true or false.', 422);
        }

        $actor = $this->actors->forUser($user);
        $id = CorrelationId::fromHeaderOrGenerate($request->getHeaderLine(ParserContract::HEADER_CORRELATION_ID));
        try {
            if ($publicId === null) {
                $token = $body['parseToken'] ?? null;
                if ($token !== null && (!is_string($token) || $token === '')) {
                    return $this->responder->error('RFQ_INPUT_INVALID', 'parseToken must be the match, or null for lines typed in.', 422);
                }
                $view = $this->review->exportNew($actor, $user->emailAddress, $token, QuoteHeaderEdit::fromInput($body['header'] ?? null), $lines ?? [], $includePrices, $id);
            } else {
                $header = array_key_exists('header', $body) ? QuoteHeaderEdit::fromInput($body['header']) : null;
                $view = $this->review->exportDraft($actor, $user->emailAddress, $publicId, $header, $lines, $includePrices, $id);
                if ($view === null) {
                    return $this->responder->error('RFQ_NOT_FOUND', 'RFQ not found.', 404);
                }
            }
        } catch (InvalidRfqValue|RfqActionForbidden|RfqStoreUnavailable|RfqParserFailure $failure) {
            return $this->failure($this->responder, $failure);
        }

        if ($output === self::SUMMARY) {
            return $this->responder->success($view->summaryToArray());
        }

        return $this->file($response, $view, $output);
    }

    private function file(ResponseInterface $response, RfqExportView $view, string $kind): ResponseInterface
    {
        if ($view->needsPrices($kind)) {
            return $this->responder->error('RFQ_PRICING_UNAVAILABLE', 'Prices are not available right now, so this file would be incomplete. Retry in a minute.', 503);
        }
        if ($view->lineCount($kind) === 0) {
            return $this->responder->error(
                'RFQ_EXPORT_EMPTY',
                $kind === RfqExportView::SUMMARY_LINES ? 'No product has a price yet, so there are no summary lines to export.' : 'There are no lines to export.',
                422,
            );
        }
        $csv = $view->csv($kind);
        $response->getBody()->write($csv);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Length', (string) strlen($csv))
            ->withHeader('Content-Disposition', sprintf('attachment; filename="%s.csv"', $kind))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store');
    }
}
