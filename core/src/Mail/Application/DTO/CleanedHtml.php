<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\DTO;

/** A body after {@see \TriCab\Core\Mail\Domain\Service\MailHtmlCleaner}. */
final readonly class CleanedHtml
{
    public function __construct(
        public string $html,
        public bool $remoteImagesBlocked,
        public ?string $quotedHtml,
    ) {
    }
}
