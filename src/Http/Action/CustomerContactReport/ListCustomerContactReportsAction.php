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
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\CustomerContactReport\Application\Service\ListCustomerContactReportsService;

final class ListCustomerContactReportsAction
{
    public function __construct(
        private readonly ListCustomerContactReportsService $listReports,
        private readonly JsonResponder $responder,
        private readonly CustomerContactReportErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $actor = CustomerContactReportActor::fromRequest($request);
            CustomerContactReportActor::assertAccess($actor);
            $criteria = CustomerContactReportListQuery::fromQueryParams(QueryParams::normalize($request->getQueryParams()));

            return $this->responder->success(CustomerContactReportDtoSerializer::listResult(
                $this->listReports->execute($criteria, CustomerContactReportActor::visibility($actor)),
            ));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
