<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\CustomerContactReport;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\CustomerContactReportActor;
use TriCab\Api\Http\Auth\CustomerContactReportErrorMapper;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\CustomerContactReport\CustomerContactReportUpload;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportClientFieldRejected;
use TriCab\Core\CustomerContactReport\Application\Service\AttachCustomerContactReportEvidenceService;
use TriCab\Core\CustomerContactReport\Application\Service\GetCustomerContactReportService;
use TriCab\Core\CustomerContactReport\Application\Service\ListCustomerContactReportAttachmentsService;
use TriCab\Core\CustomerContactReport\Domain\CustomerContactReportAssigneeMatch;
use TriCab\Core\CustomerContactReport\Domain\Exception\InvalidCustomerContactReportValue;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportRoute;
use TriCab\Core\CustomerContactReport\Module;

final class AttachCustomerContactReportEvidenceAction
{
    public function __construct(
        private readonly AttachCustomerContactReportEvidenceService $attach,
        private readonly GetCustomerContactReportService $getReport,
        private readonly ListCustomerContactReportAttachmentsService $attachments,
        private readonly JsonResponder $responder,
        private readonly CustomerContactReportErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        $publicId = '';

        try {
            $publicId = RouteParams::require($request, 'publicId');
            $actor = CustomerContactReportActor::fromRequest($request);
            CustomerContactReportActor::assertAccess($actor);
            $upload = CustomerContactReportUpload::read($request);
            $this->attach->execute(
                $actor,
                $publicId,
                $upload['name'],
                $upload['bytes'],
                $upload['mediaType'],
                $upload['fields'],
                CustomerContactReportActor::visibility($actor),
            );
            $visibility = CustomerContactReportActor::visibility($actor);
            $report = $this->getReport->execute($publicId, $visibility);
            $includeRestricted = in_array(Module::PERMISSION_READ_ALL, $actor->permissions, true);

            return $this->responder->success(CustomerContactReportDtoSerializer::detail(
                $report,
                $includeRestricted,
                $this->getReport->approvals($report->publicId),
                $actor->userId,
                $actor->emailAddress,
                $this->attachments->execute($report->publicId, $visibility),
                $includeRestricted || ($report->route === CustomerContactReportRoute::ICAR && CustomerContactReportAssigneeMatch::isCurrent(
                    $actor->userId,
                    $actor->emailAddress,
                    $report->assignedToUserId,
                    $report->assignedTo,
                    $report->assignedToEmail,
                )),
            ), 201);
        } catch (Throwable $exception) {
            $this->logFailure($publicId, $exception);

            return $this->errors->map($exception);
        }
    }

    private function logFailure(string $publicId, Throwable $exception): void
    {
        if ($exception instanceof InvalidCustomerContactReportValue || $exception instanceof CustomerContactReportClientFieldRejected) {
            return;
        }

        error_log((json_encode([
            'event' => 'customer_contact_attachment_failed',
            'publicId' => $publicId,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ], JSON_UNESCAPED_SLASHES)) ?: '{"event":"customer_contact_attachment_failed"}');
    }
}
