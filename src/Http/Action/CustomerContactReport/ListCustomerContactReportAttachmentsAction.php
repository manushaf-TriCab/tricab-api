<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\CustomerContactReport;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\CustomerContactReportActor;
use TriCab\Api\Http\Auth\CustomerContactReportErrorMapper;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\CustomerContactReport\Application\Service\ListCustomerContactReportAttachmentsService;

final class ListCustomerContactReportAttachmentsAction
{
    public function __construct(
        private readonly ListCustomerContactReportAttachmentsService $attachments,
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
            $listed = $this->attachments->execute(
                RouteParams::require($request, 'publicId'),
                CustomerContactReportActor::visibility($actor),
            );

            return $this->responder->success([
                'items' => array_map(CustomerContactReportDtoSerializer::attachment(...), $listed),
            ]);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
