<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Onboarding\Application;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Onboarding\Application\DTO\Contribution\FormContributionContext;
use TriCab\Core\Onboarding\Application\Service\ContributeToFormService;
use TriCab\Core\Onboarding\Application\Service\FormPhotoService;
use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingDocumentNotFound;
use TriCab\Core\Onboarding\Domain\Exception\SelfReviewNotPermitted;
use TriCab\Core\Onboarding\Domain\ValueObject\AdminEventType;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingAdminAuditLog;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingDocumentGenerationRepository;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingDocumentRepository;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingDocumentStore;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingFormContributionRepository;

/**
 * Phase 5.4 — photos in a form's HR part (the vehicle report).
 *
 * Held here: only a real JPEG is stored; only HR's part of a form with a photo
 * field takes one; nobody uploads to their own form; a photo field may name
 * only photos uploaded to that form; and reading one is audited.
 */
#[RequiresPhpExtension('gd')]
final class FormPhotoTest extends TestCase
{
    private const STARTER = 7;
    private const HR = 40;
    private const FORM = 'cccccccc-0000-4000-8000-000000000001';
    private const OTHER = 'cccccccc-0000-4000-8000-000000000002';

    private InMemoryOnboardingFormContributionRepository $forms;
    private InMemoryOnboardingDocumentRepository $reads;
    private InMemoryOnboardingDocumentGenerationRepository $documents;
    private InMemoryOnboardingAdminAuditLog $audit;
    private FormPhotoService $photos;

    protected function setUp(): void
    {
        $this->audit = new InMemoryOnboardingAdminAuditLog();
        $this->forms = new InMemoryOnboardingFormContributionRepository($this->audit);
        $this->reads = new InMemoryOnboardingDocumentRepository();
        $this->documents = new InMemoryOnboardingDocumentGenerationRepository($this->reads);
        $this->photos = new FormPhotoService($this->forms, $this->documents, $this->reads, new InMemoryOnboardingDocumentStore(), $this->audit, new UuidV4Generator());
        $schema = ['fields' => [['path' => 'photos', 'label' => 'Condition photos', 'type' => 'photo', 'required' => false, 'filledBy' => 'hr']]];
        $this->forms->withForm(new FormContributionContext(1, self::FORM, self::STARTER, null, 'Vehicle report', 'hr', AssignmentStatus::inProgress(), $schema));
        $this->forms->withForm(new FormContributionContext(2, self::OTHER, self::STARTER, null, 'Another vehicle', 'hr', AssignmentStatus::inProgress(), $schema));
    }

    public function test_a_jpeg_is_stored_as_the_starters_photo_and_audited(): void
    {
        $id = $this->photos->uploadAsHr($this->actor(self::HR), self::FORM, self::jpeg());

        $listed = $this->reads->listDocuments(self::STARTER);
        self::assertSame('photo', $listed[0]->documentKind);
        self::assertSame($id, $listed[0]->publicId);
        self::assertSame(AdminEventType::FORM_PHOTO_UPLOADED, $this->audit->entries[0]['event']->eventType);
    }

    public function test_anything_but_a_jpeg_is_refused(): void
    {
        $this->expectException(InvalidOnboardingValue::class);
        $this->photos->uploadAsHr($this->actor(self::HR), self::FORM, base64_encode("\x89PNG\r\n\x1a\nnot a jpeg"));
    }

    public function test_nobody_uploads_to_their_own_form(): void
    {
        $this->expectException(SelfReviewNotPermitted::class);
        $this->photos->uploadAsHr($this->actor(self::STARTER), self::FORM, self::jpeg());
    }

    public function test_a_photo_field_names_only_photos_of_the_same_form(): void
    {
        $contribute = new ContributeToFormService($this->forms, $this->audit, $this->documents);
        $mine = $this->photos->uploadAsHr($this->actor(self::HR), self::FORM, self::jpeg());
        $elsewhere = $this->photos->uploadAsHr($this->actor(self::HR), self::OTHER, self::jpeg());

        $contribute->contributeAsHr($this->actor(self::HR), self::FORM, ['photos' => $mine]);

        $this->expectException(InvalidOnboardingValue::class);
        $contribute->contributeAsHr($this->actor(self::HR), self::FORM, ['photos' => $mine . ',' . $elsewhere]);
    }

    public function test_reading_a_photo_is_audited_and_another_forms_photo_is_not_found(): void
    {
        $id = $this->photos->uploadAsHr($this->actor(self::HR), self::FORM, self::jpeg());

        $this->photos->openAsHr($this->actor(self::HR), self::FORM, $id);
        self::assertSame(['document' => $id, 'actorUserId' => self::HR, 'subjectUserId' => self::STARTER, 'accessKind' => 'read', 'permissionCode' => 'ONBOARDING.REVIEW'], $this->reads->accessLog[0]);

        $this->expectException(OnboardingDocumentNotFound::class);
        $this->photos->openAsHr($this->actor(self::HR), self::OTHER, $id);
    }

    private static function jpeg(): string
    {
        $image = imagecreatetruecolor(640, 480);
        self::assertNotFalse($image);
        ob_start();
        imagejpeg($image);

        return base64_encode((string) ob_get_clean());
    }

    private function actor(int $userId): ActorContext
    {
        return new ActorContext($userId, 'public-' . $userId, $userId . '@example.test');
    }
}
