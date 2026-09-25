<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO;

use TriCab\Core\Onboarding\Domain\Service\DocumentDeliveryPolicy;
use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;

/**
 * One document belonging to the person asking for it.
 *
 * Metadata only. The bytes never travel in a list — a listing endpoint that
 * inlined content would put a payslip PIN into every response that mentions the
 * document existing, including the ones nobody reads.
 *
 * {@see $blobContainer} and {@see $blobPath} are deliberately absent. A storage
 * path in a response is a path somebody will try, and the whole delivery
 * decision below exists so that the only route to the bytes is one that knows
 * who is asking.
 *
 * {@see deliveryMode()} is {@see DocumentDeliveryPolicy}'s answer and not this
 * object's opinion, so a new class or a changed rule lands in one place.
 */
final readonly class OnboardingDocumentView
{
    public function __construct(
        public string $publicId,
        public string $documentKind,
        public DataClass $dataClass,
        public string $contentType,
        public int $byteSize,
        public string $generatedAtUtc,
        public ?string $assignmentPublicId,
    ) {
    }

    public function deliveryMode(): string
    {
        return DocumentDeliveryPolicy::modeFor($this->dataClass);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'publicId' => $this->publicId,
            'documentKind' => $this->documentKind,
            'dataClass' => $this->dataClass->code(),
            'contentType' => $this->contentType,
            'byteSize' => $this->byteSize,
            'generatedAtUtc' => $this->generatedAtUtc,
            'assignmentPublicId' => $this->assignmentPublicId,
            'deliveryMode' => $this->deliveryMode(),
        ];
    }
}
