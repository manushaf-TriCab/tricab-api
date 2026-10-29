<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\CustomerContactReport;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\CustomerContactReportActor;
use TriCab\Api\Http\Auth\CustomerContactReportErrorMapper;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportSaveResult;
use TriCab\Core\CustomerContactReport\Application\Service\UpdateCustomerContactReportService;

/**
 * PATCH /api/v1/customer-contacts/{publicId}: autosave and amend.
 * Body carries rowVersion plus only the fields being changed. Replies 409 on a stale row version.
 */
final class UpdateCustomerContactReportAction
{
    public function __construct(
        private readonly UpdateCustomerContactReportService $updateReport,
        private readonly AdminMutationTransaction $transaction,
        private readonly JsonResponder $responder,
        private readonly CustomerContactReportErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $publicId = RouteParams::require($request, 'publicId');
            $actor = CustomerContactReportActor::fromRequest($request);
            CustomerContactReportActor::assertAccess($actor);
            $body = JsonRequestBody::requireObject($request);

            /** @var CustomerContactReportSaveResult $saved */
            $saved = $this->transaction->run(
                fn (): CustomerContactReportSaveResult => $this->updateReport->execute($actor, $publicId, $body),
            );

            return $this->responder->success(CustomerContactReportDtoSerializer::saved($saved));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
