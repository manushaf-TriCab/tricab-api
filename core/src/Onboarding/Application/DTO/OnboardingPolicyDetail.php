<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO;

use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;

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
    ) {
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
        ];
    }
}
