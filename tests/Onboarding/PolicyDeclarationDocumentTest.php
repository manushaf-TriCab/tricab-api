<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Onboarding;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use setasign\Fpdi\Fpdi;
use TriCab\Api\Onboarding\Document\PolicyDeclarationPdfMapper;
use TriCab\Core\Onboarding\Application\DTO\Document\PolicyDocumentContext;
use TriCab\Core\Onboarding\Domain\ValueObject\PolicyDeclaration;
use TriCab\Core\Tests\Onboarding\Domain\SignatureFieldTest;

/**
 * Phase 5.4 — the seven signed policies, stamped onto their own PDFs.
 * Coordinates are the source's; every page checked by eye on 24 Sep 2026.
 */
#[RequiresPhpExtension('gd')]
final class PolicyDeclarationDocumentTest extends TestCase
{
    private PolicyDeclarationPdfMapper $mapper;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->mapper = PolicyDeclarationPdfMapper::withTriCabTemplates(dirname(__DIR__, 2));
        $this->now = new \DateTimeImmutable('2026-09-24T01:00:00Z');
    }

    public function test_each_sign_off_is_the_whole_policy_with_one_signature_on_it(): void
    {
        $cases = [
            ['employee-declaration', '', 1],
            ['internet-usage-policy', 'HR-POL-001-V004', 2],
            ['corporate-credit-card-policy', 'HR-POL-009-V004', 3],
            ['company-vehicle-policy', 'HR-POL-010-V003', 2],
            ['workplace-behaviour-policy', 'HR-POL-033-V008', 10],
            ['workplace-health-and-safety-policy', 'WHS-POL-001-V005', 2],
        ];

        foreach ($cases as [$slug, $reference, $pages]) {
            $declaration = PolicyDeclaration::fromInput(PolicyDeclaration::SIGN_OFF, [
                'fullName' => 'Sam Starter',
                'signature' => SignatureFieldTest::png(400, 120),
                'accepted' => true,
                'licenceCopyAttached' => true,
            ], $reference, $this->now);
            $document = $this->mapper->render($this->context($slug, $reference, $declaration));

            self::assertNotNull($document, $slug);
            self::assertSame('policy_declaration', $document->documentKind);
            self::assertSame($pages, $this->pageCount($document->bytes), $slug . ': every page of the policy');
        }
    }

    public function test_the_code_of_conduct_adds_a_page_only_for_declared_conflict_details(): void
    {
        $answers = [
            'role' => 'employee',
            'willAvoidUndeclaredConflict' => true,
            'understandsConsequences' => true,
            'name' => 'Sam Starter',
            'divisionRegion' => 'Sales, Victoria',
            'accepted' => true,
        ];
        $none = PolicyDeclaration::fromInput(PolicyDeclaration::CODE_OF_CONDUCT, $answers + ['conflictStatus' => 'none'], 'HR-POL-036-V000', $this->now);
        $declared = PolicyDeclaration::fromInput(PolicyDeclaration::CODE_OF_CONDUCT, $answers + ['conflictStatus' => 'declared', 'conflictDetails' => 'A relative supplies cable.'], 'HR-POL-036-V000', $this->now);

        $plain = $this->mapper->render($this->context('code-of-conduct', 'HR-POL-036-V000', $none));
        $withDetails = $this->mapper->render($this->context('code-of-conduct', 'HR-POL-036-V000', $declared));

        self::assertNotNull($plain);
        self::assertNotNull($withDetails);
        self::assertSame(15, $this->pageCount($plain->bytes));
        self::assertSame(16, $this->pageCount($withDetails->bytes));
    }

    public function test_a_policy_without_a_sign_off_template_has_no_paper_copy(): void
    {
        $declaration = PolicyDeclaration::fromInput(PolicyDeclaration::SIGN_OFF, [
            'fullName' => 'Sam',
            'signature' => SignatureFieldTest::png(400, 120),
            'accepted' => true,
        ], 'HR-POL-099-V001', $this->now);

        self::assertNull($this->mapper->render($this->context('some-policy', 'HR-POL-099-V001', $declaration)));
    }

    private function context(string $slug, string $reference, PolicyDeclaration $declaration): PolicyDocumentContext
    {
        return new PolicyDocumentContext(1, 1, 'bbbbbbbb-0000-4000-8000-000000000002', $slug, 'A policy', $reference, '2026-09-24T01:00:00Z', $declaration);
    }

    private function pageCount(string $bytes): int
    {
        $file = tempnam(sys_get_temp_dir(), 'pdf');
        self::assertIsString($file);
        file_put_contents($file, $bytes);
        try {
            return (new Fpdi())->setSourceFile($file);
        } finally {
            unlink($file);
        }
    }
}
