<?php

declare(strict_types=1);

namespace TriCab\Core\LogNote\Application\Service;

use TriCab\Core\LogNote\Application\DTO\LogNoteDetails;
use TriCab\Core\LogNote\Application\Exception\LogNoteNotFound;
use TriCab\Core\LogNote\Contract\LogNoteRepository;
use TriCab\Core\LogNote\Domain\ValueObject\LogNotePublicId;

final class GetLogNoteService
{
    public function __construct(
        private readonly LogNoteRepository $notes,
    ) {
    }

    public function execute(string $publicId): LogNoteDetails
    {
        $id = LogNotePublicId::fromString($publicId);
        $details = $this->notes->findDetailsByPublicId($id);
        if ($details === null) {
            throw LogNoteNotFound::forPublicId($id->toString());
        }

        return $details;
    }
}
