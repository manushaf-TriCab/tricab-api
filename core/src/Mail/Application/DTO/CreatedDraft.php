<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\DTO;

/** The RFQ draft {@see \TriCab\Core\Mail\Contract\RfqDraftCreator} made. */
final readonly class CreatedDraft
{
    public function __construct(public string $publicId, public string $reference, public string $title)
    {
    }
}
