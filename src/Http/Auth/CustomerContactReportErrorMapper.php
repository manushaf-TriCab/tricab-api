<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Auth;

use DomainException;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use TriCab\Api\Http\Exception\HttpRequestValidationFailed;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Infrastructure\Database\DatabaseTemporarilyUnavailable;
use TriCab\Api\Infrastructure\Database\DatabaseUnavailable;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportAccessDenied;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportApprovalFailure;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportAssigneeNotFound;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportClientFieldRejected;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportConflict;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportFileFailure;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportNotAssignee;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportNotFound;
use TriCab\Core\CustomerContactReport\Domain\Exception\InvalidCustomerContactReportValue;

final class CustomerContactReportErrorMapper
{
    public function __construct(
        private readonly JsonResponder $responder,
    ) {
    }

    public function map(Throwable $exception): ResponseInterface
    {
        if ($exception instanceof HttpRequestValidationFailed) {
            return $this->responder->error($exception->errorCode, $exception->getMessage(), $exception->httpStatus);
        }

        if ($exception instanceof DatabaseTemporarilyUnavailable) {
            return $this->responder->error(
                'DATABASE_TEMPORARILY_UNAVAILABLE',
                'The database is temporarily unavailable.',
                503,
            );
        }

        if ($exception instanceof DatabaseUnavailable) {
            return $this->responder->error('DATABASE_UNAVAILABLE', 'The database is temporarily unavailable.', 503);
        }

        if ($exception instanceof CustomerContactReportFileFailure) {
            return $this->responder->error($exception->errorCode, $exception->getMessage(), $exception->httpStatus);
        }

        if ($exception instanceof CustomerContactReportApprovalFailure) {
            return $this->responder->error($exception->errorCode, $exception->getMessage(), $exception->httpStatus);
        }

        if ($exception instanceof CustomerContactReportNotAssignee) {
            return $this->responder->error(
                'CUSTOMER_CONTACT_REPORT_NOT_ASSIGNEE',
                'Only the current assignee can complete this Customer Contact report.',
                403,
            );
        }

        if ($exception instanceof CustomerContactReportAccessDenied) {
            return $this->responder->error(
                'INSUFFICIENT_PERMISSION',
                'You do not have permission to perform this action.',
                403,
            );
        }

        if ($exception instanceof CustomerContactReportConflict) {
            return $this->responder->error($exception->errorCode, $exception->getMessage(), 409);
        }

        if ($exception instanceof CustomerContactReportAssigneeNotFound) {
            return $this->responder->error('ASSIGNEE_NOT_FOUND', $exception->getMessage(), 422);
        }

        if ($exception instanceof CustomerContactReportNotFound) {
            return $this->responder->error(
                'CUSTOMER_CONTACT_REPORT_NOT_FOUND',
                'The requested Customer Contact report was not found.',
                404,
            );
        }

        if ($exception instanceof CustomerContactReportClientFieldRejected) {
            return $this->responder->error('CLIENT_FIELD_REJECTED', $exception->getMessage(), 422);
        }

        if ($exception instanceof InvalidCustomerContactReportValue || $exception instanceof InvalidArgumentException || $exception instanceof DomainException) {
            return $this->responder->error('VALIDATION_FAILED', $exception->getMessage(), 422);
        }

        return $this->responder->error('INTERNAL_ERROR', 'Unable to complete the Customer Contact request.', 500);
    }
}
