<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO;

use TriCab\Core\Onboarding\Domain\Service\FilingName;
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
        /** For HR's filing name: the person's display name, the document's title, and when the work was completed. */
        public ?string $personName = null,
        public ?string $documentTitle = null,
        public ?string $completedAtUtc = null,
    ) {
    }

    /**
     * HR's filing name, `YY.MM.DD SURNAME Given - Document name.pdf`
     * ({@see FilingName}). Built from the person, the title and the completion
     * date, never from the stored blob path: a path is attacker-influenced the
     * moment any part of it comes from a filename somebody uploaded.
     */
    public function downloadFilename(): string
    {
        $extension = match ($this->contentType) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => 'bin',
        };

        return FilingName::build(
            $this->personName,
            $this->completedAtUtc ?? $this->generatedAtUtc,
            $this->documentTitle ?? match ($this->documentKind) {
                'assessment' => 'Assessment',
                'policy_declaration' => 'Policy declaration',
                'employee_details' => 'Employee Details Form',
                'photo' => 'Photo',
                default => 'Onboarding form',
            },
            $extension,
        );
    }

    /**
     * `attachment` (or `inline`), with an ASCII fallback and the UTF-8 name (RFC 6266), so an
     * accented surname survives. FilingName has already removed quotes, slashes
     * and control characters, so neither form can split the header.
     */
    /** @param 'attachment'|'inline' $type */
    public function contentDisposition(string $type = 'attachment'): string
    {
        $name = $this->downloadFilename();
        $ascii = preg_replace('/[^\x20-\x7E]/u', '_', $name) ?? 'TriCab record';

        return sprintf("%s; filename=\"%s\"; filename*=UTF-8''%s", $type, $ascii, rawurlencode($name));
    }
}
