<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Application\DTO\OnboardingDocumentRef;
use TriCab\Core\Onboarding\Application\DTO\OnboardingDocumentView;
use TriCab\Core\Onboarding\Contract\OnboardingDocumentRepository;
use TriCab\Core\Onboarding\Contract\OnboardingPeopleRepository;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingDocumentNotFound;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingPersonNotFound;
use TriCab\Core\Onboarding\Module;

/**
 * HR's way to a person's documents (5.4) — the paper copies generated from
 * their completed work, for the personnel file.
 *
 * The same three steps as {@see OpenOwnDocumentService}, in the same order:
 * resolve against the person, audit, then hand back the reference. The
 * difference is the actor: here it is HR, and the audit row names HR against
 * the starter's document under `ONBOARDING.PEOPLE.MANAGE`, which is the read
 * the access table exists for.
 *
 * ## Always streamed
 *
 * Unlike the self-service route, HR never gets a signed URL, whatever the
 * class. A SAS URL for somebody else's paperwork is a bearer link to it, and
 * the volume here (one person's handful of documents, opened on purpose) does
 * not justify one.
 *
 * ## No leaver gate
 *
 * A departed person's records are exactly the ones HR is asked to produce,
 * and the fourteen-day window binds HR to be able to (see "The leaver policy").
 *
 * The list is not audited: it is metadata, and ordinary HR reads are not.
 */
final readonly class PersonDocumentsService
{
    public const PERMISSION = Module::PERMISSION_PEOPLE_MANAGE;

    public function __construct(
        private OnboardingPeopleRepository $people,
        private OnboardingDocumentRepository $documents,
    ) {
    }

    /**
     * @return list<OnboardingDocumentView>
     *
     * @throws OnboardingPersonNotFound
     */
    public function list(string $personPublicId): array
    {
        return $this->documents->listDocuments($this->userIdOf($personPublicId));
    }

    /**
     * @throws OnboardingPersonNotFound
     * @throws OnboardingDocumentNotFound
     */
    public function open(ActorContext $actor, string $personPublicId, string $documentPublicId): OnboardingDocumentRef
    {
        $document = $this->documents->findDocument(
            $this->userIdOf($personPublicId),
            strtolower(trim($documentPublicId)),
        ) ?? throw OnboardingDocumentNotFound::forThisPerson();

        $this->documents->recordAccess(
            $document,
            $actor->userId,
            'download',
            self::PERMISSION,
            $actor->correlationId,
            $actor->ipAddress,
            $actor->userAgent,
        );

        return $document;
    }

    private function userIdOf(string $personPublicId): int
    {
        $record = $this->people->findPerson(strtolower(trim($personPublicId)))
            ?? throw OnboardingPersonNotFound::forPublicId($personPublicId);

        return $record->userId;
    }
}
