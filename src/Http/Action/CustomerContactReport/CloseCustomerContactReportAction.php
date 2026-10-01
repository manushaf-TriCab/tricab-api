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
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportDetails;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportClientFieldRejected;
use TriCab\Core\CustomerContactReport\Application\Service\CloseCustomerContactReportService;
use TriCab\Core\CustomerContactReport\Domain\Exception\InvalidCustomerContactReportValue;

final class CloseCustomerContactReportAction
{
    public function __construct(
        private readonly CloseCustomerContactReportService $closeReport,
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
            $actor = CustomerContactReportActor::fromRequest($request);
            CustomerContactReportActor::assertAccess($actor);
            $body = JsonRequestBody::requireObject($request);

            /** @var CustomerContactReportDetails $closed */
            $closed = $this->transaction->run(
                fn (): CustomerContactReportDetails => $this->closeReport->execute($actor, $publicId, $body),
            );

            return $this->responder->success(CustomerContactReportDtoSerializer::created($closed));
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
            'event' => 'customer_contact_close_failed',
            'publicId' => $publicId,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ], JSON_UNESCAPED_SLASHES)) ?: '{"event":"customer_contact_close_failed"}');
    }
}
