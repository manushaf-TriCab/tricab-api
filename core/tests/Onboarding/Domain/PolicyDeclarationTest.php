<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Onboarding\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Onboarding\Domain\ValueObject\PolicyDeclaration;

/**
 * Phase 5.4 — what a starter gives when they acknowledge a signed policy.
 * Owner decision, 24 Sep 2026: stored on the acknowledgement itself.
 */
#[RequiresPhpExtension('gd')]
final class PolicyDeclarationTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        // 23:30 UTC is already the next day in Melbourne.
        $this->now = new \DateTimeImmutable('2026-09-23T23:30:00Z');
    }

    public function test_the_version_decides_which_declaration_a_policy_asks_for(): void
    {
        self::assertSame(PolicyDeclaration::CODE_OF_CONDUCT, PolicyDeclaration::kindFor('HR-POL-036-V000', 'code-of-conduct'));
        self::assertSame(PolicyDeclaration::SIGN_OFF, PolicyDeclaration::kindFor(null, 'employee-declaration'));
        foreach (PolicyDeclaration::SIGN_OFF_REFERENCES as $reference) {
            self::assertSame(PolicyDeclaration::SIGN_OFF, PolicyDeclaration::kindFor($reference, 'anything'), $reference);
        }
        self::assertNull(PolicyDeclaration::kindFor('HR-POL-034-V000', 'leave-policy'), 'The Leave Policy is acknowledged, not signed.');
        self::assertNull(PolicyDeclaration::kindFor(null, 'demo-whs-policy'));
    }

    public function test_a_sign_off_keeps_the_name_and_signature_and_takes_the_date_from_the_server(): void
    {
        $declaration = PolicyDeclaration::fromInput(PolicyDeclaration::SIGN_OFF, [
            'fullName' => '  Sam   Starter ',
            'signature' => SignatureFieldTest::png(400, 120),
            'accepted' => true,
            'date' => '1999-01-01',
        ], 'HR-POL-001-V004', $this->now);

        self::assertSame('Sam Starter', $declaration->text('fullName'));
        self::assertSame('2026-09-24', $declaration->text('date'), 'The date is TriCab\'s today, never the one sent.');
        self::assertArrayNotHasKey('licenceCopyAttached', $declaration->toArray());

        $public = $declaration->toPublicArray();
        self::assertArrayNotHasKey('signature', $public, 'The image is not sent back to a browser.');
        self::assertTrue($public['signed']);
        self::assertSame('sign_off', $public['kind']);

        $stored = PolicyDeclaration::fromStoredJson($declaration->toJson());
        self::assertNotNull($stored);
        self::assertSame($declaration->toArray(), $stored->toArray());
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function incompleteSignOffs(): iterable
    {
        $signature = SignatureFieldTest::png(400, 120);
        yield 'no name' => [['signature' => $signature, 'accepted' => true], 'full name'];
        yield 'no signature' => [['fullName' => 'Sam', 'accepted' => true], 'signature'];
        yield 'not a png' => [['fullName' => 'Sam', 'signature' => 'data:image/png;base64,AAAA', 'accepted' => true], 'signature'];
        yield 'not agreed' => [['fullName' => 'Sam', 'signature' => $signature, 'accepted' => 'yes'], 'agreement'];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('incompleteSignOffs')]
    public function test_an_incomplete_sign_off_is_refused_by_what_is_missing(array $input, string $named): void
    {
        $this->expectException(InvalidOnboardingValue::class);
        $this->expectExceptionMessage($named);
        PolicyDeclaration::fromInput(PolicyDeclaration::SIGN_OFF, $input, 'HR-POL-009-V004', $this->now);
    }

    public function test_the_vehicle_policy_also_needs_the_licence_copy_confirmed(): void
    {
        $input = ['fullName' => 'Sam', 'signature' => SignatureFieldTest::png(400, 120), 'accepted' => true];
        try {
            PolicyDeclaration::fromInput(PolicyDeclaration::SIGN_OFF, $input, PolicyDeclaration::VEHICLE_POLICY_REFERENCE, $this->now);
            self::fail('A vehicle sign-off without the licence copy was accepted.');
        } catch (InvalidOnboardingValue $refused) {
            self::assertStringContainsString('driver licence', $refused->getMessage());
        }

        $declaration = PolicyDeclaration::fromInput(PolicyDeclaration::SIGN_OFF, $input + ['licenceCopyAttached' => true], PolicyDeclaration::VEHICLE_POLICY_REFERENCE, $this->now);
        self::assertTrue($declaration->flag('licenceCopyAttached'));
    }

    public function test_the_code_of_conduct_records_its_answers_including_a_no(): void
    {
        $declaration = PolicyDeclaration::fromInput(PolicyDeclaration::CODE_OF_CONDUCT, $this->conduct(['accepted' => false]), 'HR-POL-036-V000', $this->now);

        self::assertSame('employee', $declaration->text('role'));
        self::assertSame('', $declaration->text('conflictDetails'), 'No details are kept when none is declared.');
        self::assertFalse($declaration->toArray()['accepted'], 'A No is recorded as given, as the source did.');
        self::assertArrayNotHasKey('signed', $declaration->toPublicArray(), 'The Code of Conduct has no signature.');
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function incompleteConduct(): iterable
    {
        yield 'no role' => [['role' => 'contractor'], 'role'];
        yield 'no status' => [['conflictStatus' => null], 'conflict-of-interest status'];
        yield 'declared without details' => [['conflictStatus' => 'declared', 'conflictDetails' => ' '], 'details'];
        yield 'a declaration unticked' => [['understandsConsequences' => false], 'tick both'];
        yield 'no division' => [['divisionRegion' => ''], 'division'];
        yield 'no final answer' => [['accepted' => null], 'Yes or No'];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('incompleteConduct')]
    public function test_an_incomplete_code_of_conduct_is_refused_by_what_is_missing(array $override, string $named): void
    {
        $this->expectException(InvalidOnboardingValue::class);
        $this->expectExceptionMessage($named);
        PolicyDeclaration::fromInput(PolicyDeclaration::CODE_OF_CONDUCT, $this->conduct($override), 'HR-POL-036-V000', $this->now);
    }

    public function test_something_that_is_not_a_declaration_is_refused_and_a_stored_one_that_no_longer_reads_is_none(): void
    {
        try {
            PolicyDeclaration::fromInput(PolicyDeclaration::SIGN_OFF, 'signed', null, $this->now);
            self::fail('A string was accepted as a declaration.');
        } catch (InvalidOnboardingValue) {
        }
        self::assertNull(PolicyDeclaration::fromStoredJson(null));
        self::assertNull(PolicyDeclaration::fromStoredJson('{"kind":"other","values":{}}'));
        self::assertNull(PolicyDeclaration::fromStoredJson('not json'));
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function conduct(array $override = []): array
    {
        return $override + [
            'role' => 'employee',
            'conflictStatus' => 'none',
            'conflictDetails' => 'ignored when none is declared',
            'willAvoidUndeclaredConflict' => true,
            'understandsConsequences' => true,
            'name' => 'Sam Starter',
            'divisionRegion' => 'Sales, Victoria',
            'accepted' => true,
        ];
    }
}
