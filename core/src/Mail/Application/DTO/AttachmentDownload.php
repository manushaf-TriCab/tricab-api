<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\DTO;

/** An attachment's bytes, fetched from Graph for one download. Untrusted: name and type came from the sender. */
final readonly class AttachmentDownload
{
    public function __construct(public string $fileName, public string $mimeType, public string $content)
    {
    }
}
