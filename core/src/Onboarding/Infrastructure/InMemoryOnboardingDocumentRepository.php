<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Infrastructure;

use TriCab\Core\Onboarding\Application\DTO\OnboardingDocumentRef;
use TriCab\Core\Onboarding\Application\DTO\OnboardingDocumentView;
use TriCab\Core\Onboarding\Contract\OnboardingDocumentRepository;

/**
 * The default binding, and what the HTTP tests run against.
 *
 * Empty unless a test seeds it, which is also the honest production behaviour
 * today: Phase 5 generates documents and nothing generates them yet.
 *
 * {@see $accessLog} is the point of this double. The audit row is the whole
 * argument for streaming C3 and C4 through the API, so a test has to be able to
 * assert that a read wrote one — and, just as importantly, that a *refused* read
 * did not.
 */
final class InMemoryOnboardingDocumentRepository implements OnboardingDocumentRepository
{
    /** @var array<int, list<OnboardingDocumentRef>> */
    private array $documents = [];

    /** @var list<array{document: string, actorUserId: int, subjectUserId: int, accessKind: string, permissionCode: string}> */
    public array $accessLog = [];

    public function listDocuments(int $userId): array
    {
        return array_map(
            static fn (OnboardingDocumentRef $ref): OnboardingDocumentView => new OnboardingDocumentView(
                publicId: $ref->publicId,
                documentKind: $ref->documentKind,
                dataClass: $ref->dataClass,
                contentType: $ref->contentType,
                byteSize: $ref->byteSize,
                generatedAtUtc: $ref->generatedAtUtc,
                assignmentPublicId: null,
            ),
            $this->documents[$userId] ?? [],
        );
    }

    public function findDocument(int $userId, string $documentPublicId): ?OnboardingDocumentRef
    {
        foreach ($this->documents[$userId] ?? [] as $ref) {
            if ($ref->publicId === $documentPublicId) {
                return $ref;
            }
        }

        return null;
    }

    public function recordAccess(
        OnboardingDocumentRef $document,
        int $actorUserId,
        string $accessKind,
        string $permissionCode,
        ?string $correlationId,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        unset($correlationId, $ipAddress, $userAgent);

        $this->accessLog[] = [
            'document' => $document->publicId,
            'actorUserId' => $actorUserId,
            'subjectUserId' => $document->ownerUserId,
            'accessKind' => $accessKind,
            'permissionCode' => $permissionCode,
        ];
    }

    public function withDocument(int $userId, OnboardingDocumentRef $document): void
    {
        $this->documents[$userId][] = $document;
    }

    public function clear(): void
    {
        $this->documents = [];
        $this->accessLog = [];
    }
}
