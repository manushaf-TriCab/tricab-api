<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\Service;

use Throwable;
use TriCab\Core\Onboarding\Contract\OnboardingDocumentGenerationRepository;
use TriCab\Core\Onboarding\Contract\OnboardingDocumentStore;
use TriCab\Core\Onboarding\Contract\OnboardingPolicyDocumentRenderer;
use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;

/**
 * Generates the signed copy of a policy after its acknowledgement commits (5.4).
 *
 * Same rules as {@see GenerateAssessmentDocumentService}: never part of the
 * write; a failure is logged by class and swallowed; once per acknowledgement,
 * held by the blob path, which is the acknowledgement's own random public id.
 *
 * The copy carries a name and a signature, so it is ordinary HR data (C2), as
 * the signed forms are.
 */
final readonly class GeneratePolicyDocumentService
{
    public function __construct(
        private OnboardingDocumentGenerationRepository $repository,
        private OnboardingPolicyDocumentRenderer $renderer,
        private OnboardingDocumentStore $store,
    ) {
    }

    public function generate(int $userId, string $assignmentPublicId): ?string
    {
        try {
            $context = $this->repository->findPolicyDocumentContext($userId, $assignmentPublicId);
            if ($context === null) {
                return null;
            }
            $path = sprintf('%d/policy-%s.pdf', $userId, strtolower($context->acknowledgementPublicId));
            if ($this->repository->hasDocumentAtPath($path)) {
                return null;
            }

            $document = $this->renderer->render($context);
            if ($document === null) {
                return null;
            }

            $stored = $this->store->put($path, $document);

            return $this->repository->recordPolicyDocument($context, $document, DataClass::ordinaryHr(), $stored['container'], $stored['path']);
        } catch (Throwable $failure) {
            error_log(sprintf(
                'onboarding.document.policy_generation_failed {"assignment":"%s","error":"%s"}',
                $assignmentPublicId,
                (new \ReflectionClass($failure))->getShortName(),
            ));

            return null;
        }
    }
}
