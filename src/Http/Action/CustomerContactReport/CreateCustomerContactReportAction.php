<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\CustomerContactReport;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\CustomerContactReportActor;
use TriCab\Api\Http\Auth\CustomerContactReportErrorMapper;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportSaveResult;
use TriCab\Core\CustomerContactReport\Application\Service\CreateCustomerContactReportService;

final class CreateCustomerContactReportAction
{
    public function __construct(
        private readonly CreateCustomerContactReportService $createReport,
        private readonly AdminMutationTransaction $transaction,
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
            $body = JsonRequestBody::requireObject($request);

            /** @var CustomerContactReportSaveResult $created */
            $created = $this->transaction->run(
                fn (): CustomerContactReportSaveResult => $this->createReport->executeWithWarnings($actor, $body),
            );

            return $this->responder->success(CustomerContactReportDtoSerializer::saved($created), 201);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
