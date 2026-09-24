<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\Service;

use Throwable;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Onboarding\Contract\OnboardingDocumentGenerationRepository;
use TriCab\Core\Onboarding\Contract\OnboardingDocumentStore;
use TriCab\Core\Onboarding\Contract\OnboardingFormDocumentRenderer;
use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;

/**
 * Generates the paper copy of a submitted form (5.4).
 *
 * ## After the submission, never part of it
 *
 * Called once the submission has committed. A template that will not render, or
 * a blob store that is down, must not turn a complete, valid submission into an
 * error the new starter sees — the submission is the record; the PDF is a copy
 * of it for HR's file. A failure is logged by class name only and the form
 * stays submitted; the reviewer sees there is no document yet.
 *
 * ## Once per revision
 *
 * An idempotent replay of the submit calls this again, and finds the document
 * already there. A revision sent back and resubmitted is a new submission and
 * gets its own copy; the earlier one stays, as the earlier submission does.
 *
 * ## The copy is as sensitive as the most sensitive thing on it
 *
 * The document's class is the form's access group: an `employee-details` PDF
 * carries a bank account and a payslip PIN, so it is C3 and is streamed and
 * audited on every read ({@see \TriCab\Core\Onboarding\Domain\Service\DocumentDeliveryPolicy}).
 * The blob path is a random id under the person's user id — no name, no form
 * title, nothing a storage listing would disclose.
 */
final readonly class GenerateFormSubmissionDocumentService
{
    public function __construct(
        private OnboardingDocumentGenerationRepository $repository,
        private OnboardingFormDocumentRenderer $renderer,
        private OnboardingDocumentStore $store,
        private UuidV4Generator $uuids,
    ) {
    }

    /**
     * @param array<string, mixed> $answers the nested payload exactly as submitted
     *
     * @return ?string the document's public id, or null when none was made
     */
    public function generate(int $userId, string $assignmentPublicId, array $answers): ?string
    {
        try {
            $submission = $this->repository->findLatestFormSubmission($userId, $assignmentPublicId);
            if ($submission === null || $this->repository->hasDocumentForFormSubmission($submission->formSubmissionId)) {
                return null;
            }

            $document = $this->renderer->render($submission, $answers);
            if ($document === null) {
                return null;
            }

            $path = sprintf('%d/%s.pdf', $userId, $this->uuids->generate()->toString());
            $stored = $this->store->put($path, $document);

            return $this->repository->recordFormDocument(
                $submission,
                $document,
                self::classFor($submission->accessGroup),
                $stored['container'],
                $stored['path'],
            );
        } catch (Throwable $failure) {
            error_log(sprintf(
                'onboarding.document.generation_failed {"assignment":"%s","error":"%s"}',
                $assignmentPublicId,
                (new \ReflectionClass($failure))->getShortName(),
            ));

            return null;
        }
    }

    public static function classFor(string $accessGroup): DataClass
    {
        return match ($accessGroup) {
            'payroll' => DataClass::payroll(),
            'restricted_hr' => DataClass::restrictedHr(),
            'hr' => DataClass::ordinaryHr(),
            // Unknown is treated as the most restricted class there is.
            default => DataClass::restrictedHr(),
        };
    }
}
