<?php

declare(strict_types=1);

namespace TriCab\Core\LogNote\Application\Service;

use TriCab\Core\Customer\Application\Exception\CustomerNotFound;
use TriCab\Core\Customer\Contract\CustomerRepository;
use TriCab\Core\Customer\Domain\ValueObject\CustomerPublicId;
use TriCab\Core\LogNote\Application\DTO\LogNoteListCriteria;
use TriCab\Core\LogNote\Application\DTO\LogNoteListResult;
use TriCab\Core\LogNote\Contract\LogNoteRepository;

final class ListLogNotesService
{
    public function __construct(
        private readonly LogNoteRepository $notes,
        private readonly CustomerRepository $customers,
    ) {
    }

    public function execute(LogNoteListCriteria $criteria): LogNoteListResult
    {
        if ($criteria->customerPublicId !== null) {
            $publicId = CustomerPublicId::fromString($criteria->customerPublicId);
            if ($this->customers->findIdByPublicId($publicId) === null) {
                throw CustomerNotFound::forPublicId($publicId->toString());
            }
        }

        return $this->notes->list($criteria);
    }
}
