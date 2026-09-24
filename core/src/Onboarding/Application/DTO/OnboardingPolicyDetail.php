<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO;

use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Onboarding\Domain\ValueObject\PolicyDeclaration;

/**
 * One policy as the person allocated it sees it, with the wording they are being
 * asked to accept.
 *
 * {@see $declarationText} comes from the server and is stored verbatim on the
 * acknowledgement. It is deliberately not something a client sends: the
 * acknowledgement row is the evidence, and evidence whose wording the signatory
 * supplied proves nothing. A browser that posted "I have skimmed this" would
 * otherwise write exactly that into the record.
 *
 * {@see $contentBlocks} is structured content rather than HTML, matching
 * `PolicyVersions.ContentJson` and its comment: policy text is authored by HR,
 * shown to every new starter, and is therefore an injection path. The renderer
 * on the web side takes blocks and emits elements; there is no `dangerouslySet`
 * anything on this route.
 *
 * {@see $requiresAcknowledgement} false is a policy that is read, not accepted:
 * the page offers "Mark as read", and finishing it writes a status event but no
 * acknowledgement row, so {@see $acknowledgedAtUtc} stays null and
 * {@see $status} is what says it is done.
 */
final readonly class OnboardingPolicyDetail
{
    /**
     * @param list<array<string, mixed>> $contentBlocks
     */
    public function __construct(
        public string $assignmentPublicId,
        public string $title,
        public string $versionPublicId,
        public int $versionNumber,
        public AssignmentStatus $status,
        public array $contentBlocks,
        public string $declarationText,
        public ?string $effectiveFrom,
        public ?string $acknowledgedAtUtc,
        public bool $requiresAcknowledgement = true,
        public ?string $summary = null,
        public ?string $chapterTitle = null,
        public ?string $documentReference = null,
        public ?string $issuedLabel = null,
        public ?string $reviewDueLabel = null,
        public ?string $declarationKind = null,
        public ?PolicyDeclaration $declaration = null,
    ) {
    }

    /** The same policy after the person finished it. */
    public function finished(AssignmentStatus $status, ?string $acknowledgedAtUtc, ?PolicyDeclaration $declaration = null): self
    {
        return new self(
            $this->assignmentPublicId,
            $this->title,
            $this->versionPublicId,
            $this->versionNumber,
            $status,
            $this->contentBlocks,
            $this->declarationText,
            $this->effectiveFrom,
            $acknowledgedAtUtc,
            $this->requiresAcknowledgement,
            $this->summary,
            $this->chapterTitle,
            $this->documentReference,
            $this->issuedLabel,
            $this->reviewDueLabel,
            $this->declarationKind,
            $declaration ?? $this->declaration,
        );
    }

    public function isAcknowledged(): bool
    {
        return $this->acknowledgedAtUtc !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'assignmentPublicId' => $this->assignmentPublicId,
            'itemType' => 'policy',
            'title' => $this->title,
            'versionPublicId' => $this->versionPublicId,
            'versionNumber' => $this->versionNumber,
            'status' => $this->status->toString(),
            'statusLabel' => $this->status->label(),
            'contentBlocks' => $this->contentBlocks,
            'declarationText' => $this->declarationText,
            'effectiveFrom' => $this->effectiveFrom,
            'acknowledgedAtUtc' => $this->acknowledgedAtUtc,
            'isAcknowledged' => $this->isAcknowledged(),
            'requiresAcknowledgement' => $this->requiresAcknowledgement,
            'summary' => $this->summary,
            'chapterTitle' => $this->chapterTitle,
            'documentReference' => $this->documentReference,
            'issuedLabel' => $this->issuedLabel,
            'reviewDueLabel' => $this->reviewDueLabel,
            'declarationKind' => $this->declarationKind,
            'declaration' => $this->declaration?->toPublicArray(),
        ];
    }
}
