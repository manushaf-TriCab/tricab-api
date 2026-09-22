<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO;

use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;

/**
 * Everything needed to fetch one document's bytes, including where they live.
 *
 * Internal, like {@see OnboardingAttemptRecord}, and for the same reason: it has
 * no `toArray()`, because a storage container and path in a response is a path
 * somebody will try directly. What a client receives is
 * {@see OnboardingDocumentView}, which carries neither.
 *
 * A reference is only ever produced by a repository method that took a user id
 * and filtered on it, so holding one is already the proof that the person asking
 * owns it. Nothing downstream re-checks ownership, and nothing should: a second
 * check implies the first might be missing, and then somebody removes the first.
 */
final readonly class OnboardingDocumentRef
{
    public function __construct(
        public string $publicId,
        public int $ownerUserId,
        public string $documentKind,
        public DataClass $dataClass,
        public string $blobContainer,
        public string $blobPath,
        public string $contentType,
        public int $byteSize,
        public string $contentSha256,
        public string $generatedAtUtc,
    ) {
    }

    /** A filename safe to put in a Content-Disposition header. */
    public function downloadFilename(): string
    {
        $extension = match ($this->contentType) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => 'bin',
        };

        // Built from the document KIND and the public id, never from the stored
        // blob path. A path is attacker-influenced the moment any part of it
        // comes from a filename somebody uploaded, and a header is exactly where
        // that turns into a traversal or a header split.
        $kind = preg_replace('/[^a-z0-9_-]/', '', strtolower($this->documentKind)) ?? 'document';

        return sprintf('%s-%s.%s', $kind === '' ? 'document' : $kind, $this->publicId, $extension);
    }
}
