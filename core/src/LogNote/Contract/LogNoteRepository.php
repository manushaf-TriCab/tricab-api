<?php

declare(strict_types=1);

namespace TriCab\Core\LogNote\Contract;

use TriCab\Core\LogNote\Application\DTO\LogNoteDetails;
use TriCab\Core\LogNote\Application\DTO\LogNoteListCriteria;
use TriCab\Core\LogNote\Application\DTO\LogNoteListResult;
use TriCab\Core\LogNote\Domain\Entity\LogNote;
use TriCab\Core\LogNote\Domain\ValueObject\LogNotePublicId;

interface LogNoteRepository
{
    public function save(LogNote $note): LogNoteDetails;

    public function findDetailsByPublicId(LogNotePublicId $publicId): ?LogNoteDetails;

    public function list(LogNoteListCriteria $criteria): LogNoteListResult;
}
