<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Auth;

use DomainException;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use TriCab\Api\Assistant\Infrastructure\Foundry\FoundryProviderUnavailable;
use TriCab\Api\Http\Exception\HttpRequestValidationFailed;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Infrastructure\Database\DatabaseTemporarilyUnavailable;
use TriCab\Api\Infrastructure\Database\DatabaseUnavailable;
use TriCab\Core\Customer\Application\Exception\CustomerContactNotFound;
use TriCab\Core\Customer\Application\Exception\CustomerNotFound;
use TriCab\Core\Customer\Domain\Exception\InvalidCustomerValue;
use TriCab\Core\LogNote\Application\Exception\LogNoteClientIdentityRejected;
use TriCab\Core\LogNote\Application\Exception\LogNoteContactCustomerMismatch;
use TriCab\Core\LogNote\Application\Exception\LogNoteNotFound;
use TriCab\Core\LogNote\Domain\Exception\InvalidLogNoteValue;

final class LogNoteErrorMapper
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

        if ($exception instanceof CustomerNotFound) {
            return $this->responder->error('CUSTOMER_NOT_FOUND', 'The requested customer was not found.', 404);
        }

        if ($exception instanceof CustomerContactNotFound) {
            return $this->responder->error('CUSTOMER_CONTACT_NOT_FOUND', 'The requested customer contact was not found.', 404);
        }

        if ($exception instanceof LogNoteNotFound) {
            return $this->responder->error('LOG_NOTE_NOT_FOUND', 'The requested log note was not found.', 404);
        }

        if ($exception instanceof LogNoteContactCustomerMismatch) {
            return $this->responder->error('CONTACT_CUSTOMER_MISMATCH', $exception->getMessage(), 422);
        }

        if ($exception instanceof LogNoteClientIdentityRejected) {
            return $this->responder->error('CLIENT_IDENTITY_REJECTED', $exception->getMessage(), 422);
        }

        if ($exception instanceof InvalidLogNoteValue || $exception instanceof InvalidCustomerValue || $exception instanceof InvalidArgumentException) {
            return $this->responder->error('VALIDATION_FAILED', $exception->getMessage(), 422);
        }

        if ($exception instanceof FoundryProviderUnavailable) {
            return $this->responder->error(
                'SUMMARISE_UNAVAILABLE',
                'AI summarisation is temporarily unavailable.',
                503,
            );
        }

        if ($exception instanceof DomainException) {
            return $this->responder->error('VALIDATION_FAILED', $exception->getMessage(), 422);
        }

        return $this->responder->error('INTERNAL_ERROR', 'Unable to complete the log notes request.', 500);
    }
}
