<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingAdminEvent;
use TriCab\Core\Onboarding\Application\DTO\Document\RenderedDocument;
use TriCab\Core\Onboarding\Application\DTO\OnboardingDocumentRef;
use TriCab\Core\Onboarding\Contract\OnboardingAdminAuditLog;
use TriCab\Core\Onboarding\Contract\OnboardingDocumentGenerationRepository;
use TriCab\Core\Onboarding\Contract\OnboardingDocumentRepository;
use TriCab\Core\Onboarding\Contract\OnboardingDocumentStore;
use TriCab\Core\Onboarding\Contract\OnboardingFormContributionRepository;
use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingAssignmentNotFound;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingDocumentNotFound;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict;
use TriCab\Core\Onboarding\Domain\Exception\SelfReviewNotPermitted;
use TriCab\Core\Onboarding\Domain\Service\FormSchemaRules;
use TriCab\Core\Onboarding\Domain\ValueObject\AdminEventType;
use TriCab\Core\Onboarding\Domain\ValueObject\PhotoImage;

/**
 * Photos for a form's HR part (5.4) — the vehicle report's condition photos.
 *
 * Owner decision, 24 Sep 2026: in the private onboarding container, recorded
 * in `DocumentReferences` (kind `photo`, C2), read streamed and audited.
 * Upload is the same rules as filling HR's part: HR only, not for one's own
 * form, not once the form is accepted, and only for a form that has an HR
 * photo field. The field's answer then names the photos by id, and
 * {@see ContributeToFormService} accepts only photos uploaded to that form.
 */
final readonly class FormPhotoService
{
    public const PERMISSION = 'ONBOARDING.REVIEW';

    public function __construct(
        private OnboardingFormContributionRepository $forms,
        private OnboardingDocumentGenerationRepository $documents,
        private OnboardingDocumentRepository $reads,
        private OnboardingDocumentStore $store,
        private OnboardingAdminAuditLog $audit,
        private UuidV4Generator $uuids,
    ) {
    }

    public function uploadAsHr(ActorContext $actor, string $assignmentPublicId, mixed $jpegBase64): string
    {
        $form = $this->forms->findContext(strtolower(trim($assignmentPublicId)))
            ?? throw OnboardingAssignmentNotFound::forThisPerson();
        if ($actor->userId === $form->subjectUserId) {
            throw SelfReviewNotPermitted::contribution(UserId::fromInt($actor->userId));
        }
        if ($form->status->isFinal()) {
            throw OnboardingWriteConflict::alreadyRecorded('completed form');
        }
        $hasPhotoField = false;
        foreach (is_array($form->schema['fields'] ?? null) ? $form->schema['fields'] : [] as $field) {
            if (is_array($field) && ($field['type'] ?? null) === 'photo' && FormSchemaRules::filledBy($field) === FormSchemaRules::FILLED_BY_HR) {
                $hasPhotoField = true;
            }
        }
        if (!$hasPhotoField) {
            throw InvalidOnboardingValue::for('photo', 'this form takes no photos');
        }

        $photo = PhotoImage::fromBase64($jpegBase64);
        $rendered = new RenderedDocument('photo', 'image/jpeg', $photo->jpeg);
        $stored = $this->store->put(sprintf('%d/photo-%s.jpg', $form->subjectUserId, $this->uuids->generate()->toString()), $rendered);
        $publicId = $this->documents->recordPhoto($form, $rendered, $stored['container'], $stored['path']);

        $this->audit->append($actor, OnboardingAdminEvent::succeeded(
            AdminEventType::FORM_PHOTO_UPLOADED,
            $form->subjectUserId,
            $form->assignmentPublicId,
            sprintf('Uploaded a photo to "%s".', $form->title),
            ['role' => FormSchemaRules::FILLED_BY_HR],
            self::PERMISSION,
        ));

        return $publicId;
    }

    /**
     * One of a form's photos, for HR to see, with the read audited first — as
     * every onboarding document read is.
     */
    public function openAsHr(ActorContext $actor, string $assignmentPublicId, string $photoPublicId): OnboardingDocumentRef
    {
        $form = $this->forms->findContext(strtolower(trim($assignmentPublicId)))
            ?? throw OnboardingAssignmentNotFound::forThisPerson();
        $id = strtolower(trim($photoPublicId));
        if ($this->documents->photosOf($form->assignmentId, [$id]) === []) {
            throw OnboardingDocumentNotFound::forThisPerson();
        }
        $photo = $this->reads->findDocument($form->subjectUserId, $id) ?? throw OnboardingDocumentNotFound::forThisPerson();
        $this->reads->recordAccess($photo, $actor->userId, 'read', self::PERMISSION, $actor->correlationId, $actor->ipAddress, $actor->userAgent);

        return $photo;
    }
}
