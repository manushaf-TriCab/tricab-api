<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Auth;

use DomainException;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use TriCab\Api\Http\Exception\HttpRequestValidationFailed;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Customer\Application\Exception\CustomerNotFound;
use TriCab\Core\Expense\Application\Exception\ExpenseAccessDenied;
use TriCab\Core\Expense\Application\Exception\ExpenseAttachmentUnavailable;
use TriCab\Core\Expense\Application\Exception\ExpenseBankFeedNotFound;
use TriCab\Core\Expense\Application\Exception\ExpenseLegacyReadOnly;
use TriCab\Core\Expense\Application\Exception\ExpenseManagerRequired;
use TriCab\Core\Expense\Application\Exception\ExpenseOcrFailed;
use TriCab\Core\Expense\Application\Exception\ExpenseOcrUnavailable;
use TriCab\Core\Expense\Application\Exception\ExpensePeriodNotFound;
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

        if ($exception instanceof ExpenseLegacyReadOnly) {
            return $this->responder->error('TRIEXPENSE_LEGACY_READ_ONLY', $exception->getMessage(), 422);
        }

        if ($exception instanceof ExpenseReceiptNotFound) {
            return $this->responder->error('TRIEXPENSE_RECEIPT_NOT_FOUND', $exception->getMessage(), 404);
        }

        if ($exception instanceof ExpenseBankFeedNotFound) {
            return $this->responder->error('TRIEXPENSE_BANK_FEED_NOT_FOUND', $exception->getMessage(), 404);
        }

        if ($exception instanceof ExpensePeriodNotFound) {
            return $this->responder->error('TRIEXPENSE_PERIOD_NOT_FOUND', $exception->getMessage(), 404);
        }

        if ($exception instanceof CustomerNotFound) {
            return $this->responder->error('TRIEXPENSE_CUSTOMER_NOT_FOUND', $exception->getMessage(), 404);
        }

        if ($exception instanceof ExpenseManagerRequired) {
            return $this->responder->error('TRIEXPENSE_MANAGER_REQUIRED', $exception->getMessage(), 422);
        }

        if ($exception instanceof ExpenseConcurrencyConflict) {
            return $this->responder->payload([
                'success' => false,
                'error' => [
                    'code' => 'TRIEXPENSE_CONCURRENCY_CONFLICT',
                    'message' => $exception->getMessage(),
                    'reloadRequired' => true,
                ],
            ], 409);
        }

        if ($exception instanceof ExpenseAttachmentUnavailable) {
            $message = $exception->getMessage();
            if (str_contains($message, 'migrated')) {
                return $this->responder->error('LEGACY_ATTACHMENT_NOT_MIGRATED', $message, 422);
            }
            if (str_contains($message, 'not allowed')) {
                return $this->responder->error('TRIEXPENSE_ATTACHMENT_INVALID', $message, 422);
            }
            if (str_contains($message, 'Uploaded attachment')) {
                return $this->responder->error('TRIEXPENSE_ATTACHMENT_MISSING', $message, 422);
            }
            if (str_contains($message, 'not found')) {
                return $this->responder->error('TRIEXPENSE_ATTACHMENT_NOT_FOUND', $message, 404);
            }

            return $this->responder->error('TRIEXPENSE_ATTACHMENT_UNAVAILABLE', $message, 503);
        }

        if ($exception instanceof ExpenseOcrUnavailable) {
            $message = $exception->getMessage();
            // "not enabled" is configuration (503); a rejected document is the
            // caller's file and will never succeed on retry (422).
            if (str_contains($message, 'cannot be processed')) {
                return $this->responder->error('TRIEXPENSE_OCR_INPUT_REJECTED', $message, 422);
            }

            return $this->responder->error('TRIEXPENSE_OCR_DISABLED', $message, 503);
        }

        if ($exception instanceof ExpenseOcrFailed) {
            return $this->responder->error('TRIEXPENSE_OCR_FAILED', $exception->getMessage(), 502);
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
