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
use TriCab\Core\CustomerContactReport\Application\Service\GetCustomerContactReportService;
use TriCab\Core\CustomerContactReport\Application\Service\ListCustomerContactReportAttachmentsService;
use TriCab\Core\CustomerContactReport\Domain\CustomerContactReportAssigneeMatch;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportRoute;
use TriCab\Core\CustomerContactReport\Module;

final class GetCustomerContactReportAction
{
    public function __construct(
        private readonly GetCustomerContactReportService $getReport,
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
            $report = $this->getReport->execute(
                RouteParams::require($request, 'publicId'),
                CustomerContactReportActor::visibility($actor),
            );
            $visibility = CustomerContactReportActor::visibility($actor);
            $includeRestricted = in_array(Module::PERMISSION_READ_ALL, $actor->permissions, true);
            $includeIcar = $includeRestricted || (
                $report->route === CustomerContactReportRoute::ICAR
                && CustomerContactReportAssigneeMatch::isCurrent(
                    $actor->userId,
                    $actor->emailAddress,
                    $report->assignedToUserId,
                    $report->assignedTo,
                    $report->assignedToEmail,
                )
            );

            return $this->responder->success(CustomerContactReportDtoSerializer::detail(
                $report,
                $includeRestricted,
                $this->getReport->approvals($report->publicId),
                $actor->userId,
                $actor->emailAddress,
                $this->attachments->execute($report->publicId, $visibility),
                $includeIcar,
            ));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
