<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Onboarding\Application;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Onboarding\Application\DTO\Document\PolicyDocumentContext;
use TriCab\Core\Onboarding\Application\DTO\Document\RenderedDocument;
use TriCab\Core\Onboarding\Application\Service\GeneratePolicyDocumentService;
use TriCab\Core\Onboarding\Contract\OnboardingPolicyDocumentRenderer;
use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;
use TriCab\Core\Onboarding\Domain\ValueObject\PolicyDeclaration;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingDocumentGenerationRepository;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingDocumentRepository;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingDocumentStore;
use TriCab\Core\Tests\Onboarding\Domain\SignatureFieldTest;

/** Phase 5.4 — the signed copy of a policy, made after its acknowledgement commits. */
#[RequiresPhpExtension('gd')]
final class PolicyDocumentGenerationTest extends TestCase
{
    private const USER = 7;
    private const ASSIGNMENT = 'aaaaaaaa-0000-4000-8000-000000000001';

    private InMemoryOnboardingDocumentRepository $read;
    private InMemoryOnboardingDocumentGenerationRepository $repository;
    private InMemoryOnboardingDocumentStore $store;

    protected function setUp(): void
    {
        $this->read = new InMemoryOnboardingDocumentRepository();
        $this->repository = new InMemoryOnboardingDocumentGenerationRepository($this->read);
        $this->store = new InMemoryOnboardingDocumentStore();
    }

    public function test_a_signed_acknowledgement_gets_one_ordinary_hr_copy_on_the_persons_list(): void
    {
        $this->givenSignedAcknowledgement();
        $service = $this->service($this->renderer());

        $first = $service->generate(self::USER, self::ASSIGNMENT);
        $again = $service->generate(self::USER, self::ASSIGNMENT);

        self::assertNotNull($first);
        self::assertNull($again, 'Once per acknowledgement.');
        $path = self::USER . '/policy-bbbbbbbb-0000-4000-8000-000000000002.pdf';
        self::assertArrayHasKey($path, $this->repository->paths, 'Named by the acknowledgement\'s random id.');
        $documents = $this->read->listDocuments(self::USER);
        self::assertCount(1, $documents);
        self::assertSame('policy_declaration', $documents[0]->documentKind);
        self::assertTrue($documents[0]->dataClass->equals(DataClass::ordinaryHr()));
    }

    public function test_nothing_is_made_without_a_signed_acknowledgement_or_a_template(): void
    {
        self::assertNull($this->service($this->renderer())->generate(self::USER, self::ASSIGNMENT));

        $this->givenSignedAcknowledgement();
        self::assertNull($this->service($this->renderer(null))->generate(self::USER, self::ASSIGNMENT));
        self::assertSame([], $this->repository->paths);
    }

    public function test_a_renderer_that_fails_is_logged_and_swallowed(): void
    {
        $this->givenSignedAcknowledgement();
        $failing = new class () implements OnboardingPolicyDocumentRenderer {
            public function render(PolicyDocumentContext $context): ?RenderedDocument
            {
                throw new \RuntimeException('template missing');
            }
        };

        $log = ini_set('error_log', '/dev/null');
        try {
            self::assertNull($this->service($failing)->generate(self::USER, self::ASSIGNMENT));
        } finally {
            ini_set('error_log', (string) $log);
        }
        self::assertSame([], $this->repository->paths);
    }

    private function givenSignedAcknowledgement(): void
    {
        $declaration = PolicyDeclaration::fromInput(PolicyDeclaration::SIGN_OFF, [
            'fullName' => 'Sam Starter',
            'signature' => SignatureFieldTest::png(400, 120),
            'accepted' => true,
        ], 'HR-POL-001-V004', new \DateTimeImmutable('2026-09-24T01:00:00Z'));
        $this->repository->policies[self::USER . ':' . self::ASSIGNMENT] = new PolicyDocumentContext(
            11,
            self::USER,
            'bbbbbbbb-0000-4000-8000-000000000002',
            'internet-usage-policy',
            'Internet Usage Policy',
            'HR-POL-001-V004',
            '2026-09-24T01:00:00Z',
            $declaration,
        );
    }

    private function renderer(?string $bytes = '%PDF-1.4 signed'): OnboardingPolicyDocumentRenderer
    {
        return new class ($bytes) implements OnboardingPolicyDocumentRenderer {
            public function __construct(private readonly ?string $bytes)
            {
            }

            public function render(PolicyDocumentContext $context): ?RenderedDocument
            {
                return $this->bytes === null ? null : new RenderedDocument('policy_declaration', 'application/pdf', $this->bytes);
            }
        };
    }

    private function service(OnboardingPolicyDocumentRenderer $renderer): GeneratePolicyDocumentService
    {
        return new GeneratePolicyDocumentService($this->repository, $renderer, $this->store);
    }
}
