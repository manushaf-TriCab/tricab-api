<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Onboarding\Application;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Onboarding\Application\DTO\Document\FormSubmissionForDocument;
use TriCab\Core\Onboarding\Application\DTO\Document\RenderedDocument;
use TriCab\Core\Onboarding\Application\Service\GenerateFormSubmissionDocumentService;
use TriCab\Core\Onboarding\Contract\OnboardingFormDocumentRenderer;
use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingDocumentGenerationRepository;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingDocumentRepository;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingDocumentStore;

/**
 * Phase 5.4 — the paper copy of a submitted form.
 *
 * Held here: one document per revision, however often generation is asked for;
 * a form with no template gets none; a failure never escapes to the submit; the
 * document is as sensitive as its form; and the blob path names nobody.
 */
final class FormDocumentGenerationTest extends TestCase
{
    private const USER = 77;
    private const ASSIGNMENT = '11111111-1111-4111-8111-111111111111';

    private InMemoryOnboardingDocumentRepository $read;
    private InMemoryOnboardingDocumentGenerationRepository $repository;
    private InMemoryOnboardingDocumentStore $store;

    protected function setUp(): void
    {
        $this->read = new InMemoryOnboardingDocumentRepository();
        $this->repository = new InMemoryOnboardingDocumentGenerationRepository($this->read);
        $this->store = new InMemoryOnboardingDocumentStore();
    }

    public function test_a_submitted_form_gets_one_document_on_the_persons_own_list(): void
    {
        $this->submitted('employee-details', 'payroll', 501);

        $id = $this->service()->generate(self::USER, self::ASSIGNMENT, ['surname' => 'Synthetic']);

        self::assertNotNull($id);
        $listed = $this->read->listDocuments(self::USER);
        self::assertCount(1, $listed);
        self::assertSame('employee_details', $listed[0]->documentKind);
        self::assertSame(DataClass::PAYROLL, $listed[0]->dataClass->toInt());
        self::assertSame([], $this->read->listDocuments(78), 'nobody else sees it');
    }

    public function test_asking_again_for_the_same_revision_makes_nothing_new(): void
    {
        $this->submitted('employee-details', 'payroll', 501);

        $this->service()->generate(self::USER, self::ASSIGNMENT, []);
        $again = $this->service()->generate(self::USER, self::ASSIGNMENT, []);

        self::assertNull($again);
        self::assertCount(1, $this->store->objects);
    }

    public function test_a_resubmission_is_a_new_revision_with_its_own_copy(): void
    {
        $this->submitted('employee-details', 'payroll', 501);
        $this->service()->generate(self::USER, self::ASSIGNMENT, []);

        $this->submitted('employee-details', 'payroll', 502, 2);
        $this->service()->generate(self::USER, self::ASSIGNMENT, []);

        self::assertCount(2, $this->read->listDocuments(self::USER));
    }

    public function test_a_form_with_no_template_has_no_paper_copy(): void
    {
        $this->submitted('demo-starter-details', 'hr', 501);

        self::assertNull($this->service()->generate(self::USER, self::ASSIGNMENT, []));
        self::assertSame([], $this->store->objects);
    }

    public function test_nothing_submitted_means_nothing_generated(): void
    {
        self::assertNull($this->service()->generate(self::USER, self::ASSIGNMENT, []));
    }

    public function test_a_failure_is_swallowed_and_a_later_attempt_succeeds(): void
    {
        $this->submitted('employee-details', 'payroll', 501);
        $this->store->failNext = true;

        $logged = $this->capturingErrorLog(fn () => self::assertNull($this->service()->generate(self::USER, self::ASSIGNMENT, ['payslipPin' => '4321'])));

        self::assertStringContainsString('onboarding.document.generation_failed', $logged);
        self::assertStringContainsString('RuntimeException', $logged);
        self::assertStringNotContainsString('4321', $logged, 'no answer in the log');
        self::assertSame([], $this->repository->recorded);

        self::assertNotNull($this->service()->generate(self::USER, self::ASSIGNMENT, []));
    }

    public function test_the_blob_path_is_a_random_id_under_the_user_id(): void
    {
        $this->submitted('employee-details', 'payroll', 501);

        $this->service()->generate(self::USER, self::ASSIGNMENT, ['surname' => 'Synthetic']);

        $path = array_key_first($this->store->objects);
        self::assertIsString($path);
        self::assertMatchesRegularExpression('#^77/[0-9a-f-]{36}\.pdf$#', $path);
    }

    public function test_the_class_follows_the_access_group_and_fails_closed(): void
    {
        self::assertSame(DataClass::ORDINARY_HR, GenerateFormSubmissionDocumentService::classFor('hr')->toInt());
        self::assertSame(DataClass::PAYROLL, GenerateFormSubmissionDocumentService::classFor('payroll')->toInt());
        self::assertSame(DataClass::RESTRICTED_HR, GenerateFormSubmissionDocumentService::classFor('restricted_hr')->toInt());
        self::assertSame(DataClass::RESTRICTED_HR, GenerateFormSubmissionDocumentService::classFor('something-new')->toInt());
    }

    private function service(): GenerateFormSubmissionDocumentService
    {
        return new GenerateFormSubmissionDocumentService(
            $this->repository,
            new class () implements OnboardingFormDocumentRenderer {
                public function render(FormSubmissionForDocument $submission, array $answers): ?RenderedDocument
                {
                    return $submission->formSlug === 'employee-details'
                        ? new RenderedDocument('employee_details', 'application/pdf', '%PDF-1.4 synthetic r' . $submission->revisionNumber)
                        : null;
                }
            },
            $this->store,
            new UuidV4Generator(),
        );
    }

    private function submitted(string $slug, string $accessGroup, int $submissionId, int $revision = 1): void
    {
        $this->repository->withSubmission(self::ASSIGNMENT, new FormSubmissionForDocument(
            $submissionId,
            9,
            self::USER,
            $slug,
            $accessGroup,
            $revision,
            '2026-09-24T01:00:00Z',
            'Sam Synthetic',
        ));
    }

    private function capturingErrorLog(callable $run): string
    {
        $file = tempnam(sys_get_temp_dir(), 'log');
        self::assertIsString($file);
        $previous = ini_set('error_log', $file);
        try {
            $run();
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }
        $contents = (string) file_get_contents($file);
        unlink($file);

        return $contents;
    }
}
