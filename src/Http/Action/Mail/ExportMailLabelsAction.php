<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Mail;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Mail\Application\DTO\ExportQuery;
use TriCab\Core\Mail\Application\Service\MailExportService;
use TriCab\Core\Mail\Domain\Exception\MailFailure;

/**
 * GET /api/v1/mail/export?decidedOnly&from&to: the labelled set as a CSV download (plan I3.3), one row per
 * assessed thread, sender domain only. Gated on `MAIL.REVIEW`; the service also needs `MAIL.ACCESS`.
 * Not written to the Activity tab.
 */
final class ExportMailLabelsAction
{
    use MailActionSupport;

    public function __construct(private readonly MailExportService $export, private readonly JsonResponder $responder)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $actor = $this->actor($request);
        if ($actor === null) {
            return $this->unauthenticated($this->responder);
        }
        try {
            $csv = $this->export->csv($actor, ExportQuery::fromInput(array_filter($request->getQueryParams(), 'is_string', ARRAY_FILTER_USE_KEY)));
        } catch (MailFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
        $response->getBody()->write($csv);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="mail-labelled-set.csv"')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'no-store, private');
    }
}
