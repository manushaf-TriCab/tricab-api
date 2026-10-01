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
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportApprovalOutcome;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportClientFieldRejected;
use TriCab\Core\CustomerContactReport\Application\Service\DecideCustomerContactReportApprovalService;
use TriCab\Core\CustomerContactReport\Application\Service\GetCustomerContactReportService;
use TriCab\Core\CustomerContactReport\Domain\Exception\InvalidCustomerContactReportValue;
use TriCab\Core\CustomerContactReport\Module;

final class DecideCustomerContactReportApprovalAction
{
    public function __construct(
        private readonly DecideCustomerContactReportApprovalService $decideApproval,
        private readonly GetCustomerContactReportService $getReport,
        private readonly AdminMutationTransaction $transaction,
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
            $kind = RouteParams::require($request, 'kind');
            $actor = CustomerContactReportActor::fromRequest($request);
            CustomerContactReportActor::assertAccess($actor);
            $body = JsonRequestBody::requireObject($request);

            /** @var CustomerContactReportApprovalOutcome $outcome */
            $outcome = $this->transaction->run(
                fn (): CustomerContactReportApprovalOutcome => $this->decideApproval->execute($actor, $publicId, $kind, $body),
            );
            $includeRestricted = in_array(Module::PERMISSION_READ_ALL, $actor->permissions, true);

            return $this->responder->success(CustomerContactReportDtoSerializer::detail(
                $outcome->report,
                $includeRestricted,
                $this->getReport->approvals($outcome->report->publicId),
                $actor->userId,
                $actor->emailAddress,
            ));
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
            'event' => 'customer_contact_approval_decision_failed',
            'publicId' => $publicId,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ], JSON_UNESCAPED_SLASHES)) ?: '{"event":"customer_contact_approval_decision_failed"}');
    }
}
