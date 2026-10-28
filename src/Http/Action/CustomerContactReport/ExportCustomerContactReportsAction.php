<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\CustomerContactReport;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\CustomerContactReportActor;
use TriCab\Api\Http\Auth\CustomerContactReportErrorMapper;
use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Api\Http\Request\CustomerContactReport\CustomerContactReportListQuery;
use TriCab\Core\CustomerContactReport\Application\Service\ExportCustomerContactReportsService;
use Slim\Psr7\Factory\StreamFactory;

/** GET /api/v1/customer-contacts/export: CSV with the list's filters and visibility. */
final class ExportCustomerContactReportsAction
{
    public function __construct(
        private readonly ExportCustomerContactReportsService $export,
        private readonly CustomerContactReportErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $actor = CustomerContactReportActor::fromRequest($request);
            CustomerContactReportActor::assertAccess($actor);
            $criteria = CustomerContactReportListQuery::fromQueryParams(QueryParams::normalize($request->getQueryParams()));
            $result = $this->export->execute($actor, $criteria);

            return $response
                ->withHeader('Content-Type', 'text/csv; charset=utf-8')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $result['filename'] . '"')
                ->withHeader('Cache-Control', 'no-store')
                ->withHeader('X-Row-Count', (string) $result['rows'])
                ->withHeader('X-Export-Truncated', $result['truncated'] ? '1' : '0')
                ->withBody((new StreamFactory())->createStream($result['body']));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
