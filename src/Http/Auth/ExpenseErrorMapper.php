<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Auth;

use DomainException;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use TriCab\Api\Http\Exception\HttpRequestValidationFailed;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Expense\Application\Exception\ExpenseAccessDenied;
use TriCab\Core\Expense\Application\Exception\ExpenseManagerRequired;
use TriCab\Core\Expense\Application\Exception\ExpenseReceiptNotFound;
use TriCab\Core\Expense\Domain\Exception\ExpenseConcurrencyConflict;
use TriCab\Core\Expense\Domain\Exception\InvalidExpenseStatusTransition;
use TriCab\Core\Expense\Domain\Exception\InvalidExpenseValue;

final class ExpenseErrorMapper
{
    public function __construct(private readonly JsonResponder $responder)
    {
    }

    public function map(Throwable $exception): ResponseInterface
    {
        if ($exception instanceof HttpRequestValidationFailed) {
            return $this->responder->error($exception->errorCode, $exception->getMessage(), $exception->httpStatus);
        }

        if ($exception instanceof ExpenseAccessDenied) {
            $message = $exception->getMessage();
            $code = 'TRIEXPENSE_ACCESS_DENIED';
            if (str_contains($message, 'approve') || str_contains($message, 'reject')) {
                $code = 'TRIEXPENSE_APPROVAL_FORBIDDEN';
            }

            return $this->responder->error($code, $message, 403);
        }

        if ($exception instanceof ExpenseReceiptNotFound) {
            return $this->responder->error('TRIEXPENSE_RECEIPT_NOT_FOUND', $exception->getMessage(), 404);
        }

        if ($exception instanceof ExpenseManagerRequired) {
            return $this->responder->error('TRIEXPENSE_MANAGER_REQUIRED', $exception->getMessage(), 422);
        }

        if ($exception instanceof ExpenseConcurrencyConflict) {
            return $this->responder->error('TRIEXPENSE_CONCURRENCY_CONFLICT', $exception->getMessage(), 409);
        }

        if ($exception instanceof InvalidExpenseStatusTransition) {
            return $this->responder->error('TRIEXPENSE_INVALID_STATUS_TRANSITION', $exception->getMessage(), 422);
        }

        if ($exception instanceof InvalidExpenseValue || $exception instanceof InvalidArgumentException || $exception instanceof DomainException) {
            return $this->responder->error('VALIDATION_FAILED', $exception->getMessage(), 422);
        }

        return $this->responder->error('INTERNAL_ERROR', 'An unexpected error occurred.', 500);
    }
}