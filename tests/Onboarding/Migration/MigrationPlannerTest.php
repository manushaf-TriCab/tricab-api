<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Onboarding\Migration;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Onboarding\Migration\SyntheticSourceBuilder;
use TriCab\Core\Onboarding\Migration\Plan\MigrationPlan;
use TriCab\Core\Onboarding\Migration\Plan\MigrationPlanner;
use TriCab\Core\Onboarding\Migration\Source\SourceSnapshot;
use TriCab\Core\Onboarding\Migration\Target\TargetCatalogue;
use TriCab\Core\Onboarding\Migration\Target\TargetState;

/**
 * @phpstan-import-type SnapshotDocument from SyntheticSourceBuilder
 *
 * Phase 7.3 — the preview: people matched by address only, every record
 * decided, the planted problems skipped with their reasons, and a fingerprint
 * that moves when anything the plan depends on moves.
 */
final class MigrationPlannerTest extends TestCase
{
    public function test_the_synthetic_source_plans_as_designed(): void
    {
        $plan = $this->plan();

        self::assertTrue($plan->isApprovable());
        self::assertSame([
            'people' => ['link' => 3, 'create_account' => 1, 'skip_admin' => 2, 'blocked' => 0],
            'create' => [
                'profiles' => 1, 'accounts' => 1, 'training' => 5, 'progress' => 4, 'attempts' => 3, 'answers' => 51,
                'policies' => 8, 'acknowledgements' => 5, 'forms' => 4, 'submissions' => 3, 'drafts' => 1,
                'contributions' => 0, 'documents' => 7, 'photos' => 0, 'statusEvents' => 20,
            ],
            'skip' => ['ALREADY_ASSIGNED' => 1, 'NO_PLATFORM_MODULE' => 1, 'SUPERSEDED_VERSION' => 1],
        ], $plan->counts());
    }

    public function test_people_are_matched_by_address_and_the_unknown_one_gets_an_account(): void
    {
        $people = [];
        foreach ($this->plan()->people as $person) {
            self::assertIsString($person['email']);
            $people[$person['email']] = [$person['action'], $person['userId'], $person['profileAction']];
        }

        self::assertSame([MigrationPlan::PERSON_LINK, 101, MigrationPlan::PROFILE_EXISTING], $people['demo.alex.ng@example.test']);
        self::assertSame([MigrationPlan::PERSON_CREATE_ACCOUNT, null, MigrationPlan::PROFILE_CREATE], $people['demo.casey.new@example.test']);
        self::assertSame(MigrationPlan::PERSON_SKIPPED_ADMIN, $people['hr.admin@example.test'][0], 'is_admin, no work');
        self::assertSame(MigrationPlan::PERSON_SKIPPED_ADMIN, $people['hr.lead@example.test'][0], 'ADMIN_EMAILS, no work');
    }

    public function test_attempts_carry_the_source_s_score_and_the_reviewer_as_a_platform_user(): void
    {
        $whse = $this->item('demo.alex.ng@example.test', 'corporate-whse-induction');
        $attempts = $whse['attempts'];
        self::assertIsArray($attempts);
        self::assertCount(2, $attempts);

        [$fail, $pass] = $attempts;
        self::assertIsArray($fail);
        self::assertIsArray($pass);
        self::assertSame([1, 59, false], [$fail['number'], $fail['scorePercent'], $fail['isPass']]);
        self::assertSame([2, 88, true], [$pass['number'], $pass['scorePercent'], $pass['isPass']]);
        self::assertSame(2, $pass['reviewerUserId'], 'hr.admin@example.test has platform user 2');
        self::assertCount(2, (array) $pass['documents'], 'completed and reviewed PDFs');
    }

    public function test_a_declaration_is_carried_in_the_platform_s_shape(): void
    {
        $policy = $this->item('demo.jordan.lee@example.test', 'company-vehicle-policy');
        $ack = $policy['acknowledgement'];
        self::assertIsArray($ack);
        $declaration = $ack['declaration'];
        self::assertIsArray($declaration);

        self::assertSame('completed', $policy['status']);
        self::assertSame('sign_off', $ack['declarationKind']);
        self::assertSame(['fullName', 'signature', 'date', 'accepted', 'licenceCopyAttached'], array_keys($declaration));
    }

    public function test_the_fingerprint_moves_with_the_platform_and_the_source(): void
    {
        $base = $this->plan()->fingerprint();

        self::assertSame($base, $this->plan()->fingerprint(), 'stable');
        self::assertNotSame($base, $this->plan(state: $this->state(extraAssignment: 'form:demo-starter-details'))->fingerprint(), 'a new platform assignment');
        self::assertNotSame($base, $this->plan(state: $this->state(caseyUserId: 104))->fingerprint(), 'an account created since');

        $doc = $this->document();
        $doc['tables']['assignments'][0]['status'] = 'in_progress';
        self::assertNotSame($base, $this->plan($doc)->fingerprint(), 'a changed source row');
    }

    public function test_a_record_naming_one_that_is_not_there_blocks_approval(): void
    {
        $doc = $this->document();
        $doc['tables']['assignments'][0]['module_id'] = 99;

        $plan = $this->plan($doc);

        self::assertFalse($plan->isApprovable());
        self::assertContains('DANGLING_REFERENCE', array_column($plan->findings, 'code'));
    }

    public function test_attempts_against_different_questions_are_left_out_and_the_assignment_kept(): void
    {
        $catalogue = SourceSnapshotTest::catalogue();
        $modules = $catalogue->modules;
        $whse = $modules['corporate-whse-induction'];
        $questions = $whse['questions'];
        $questions[0] = ['prompt' => 'A reworded question'] + $questions[0];
        $whse['questions'] = $questions;
        $modules['corporate-whse-induction'] = $whse;
        $reworded = new TargetCatalogue($modules, $catalogue->policies, $catalogue->forms);

        $plan = (new MigrationPlanner())->plan(SourceSnapshot::fromArray($this->document()), $reworded, $this->state());
        $whse = $this->item('demo.alex.ng@example.test', 'corporate-whse-induction', $plan);

        self::assertSame(MigrationPlan::ITEM_CREATE, $whse['action']);
        self::assertSame([], $whse['attempts']);
        self::assertContains('QUESTIONS_DIFFER', array_column($plan->findings, 'code'));
    }

    public function test_progress_naming_unknown_slides_is_left_out(): void
    {
        $doc = $this->document();
        $doc['tables']['presentation_progress'][0]['viewed_slide_ids'] = ['slide-001', 'slide-999'];

        $plan = $this->plan($doc);

        self::assertNull($this->item('demo.alex.ng@example.test', 'corporate-whse-induction', $plan)['progress']);
        self::assertContains('UNKNOWN_SLIDES', array_column($plan->findings, 'code'));
    }

    public function test_an_incomplete_declaration_skips_the_policy(): void
    {
        $doc = $this->document();
        $doc['tables']['policy_acknowledgements'][0]['declaration_data'] = ['fullName' => 'Alex Ng', 'signatureDataUrl' => 'data:image/png;base64,AAAA', 'accepted' => true];

        $plan = $this->plan($doc);

        self::assertSame('DECLARATION_INCOMPLETE', $this->item('demo.alex.ng@example.test', 'employee-declaration', $plan)['reason']);
    }

    public function test_a_tampered_document_is_left_out(): void
    {
        $doc = $this->document();
        $doc['tables']['attempts'][0]['pdf_sha256'] = str_repeat('0', 64);

        $plan = $this->plan($doc);

        self::assertContains('DOCUMENT_HASH_MISMATCH', array_column($plan->findings, 'code'));
    }

    public function test_a_real_snapshot_cannot_use_synthetic_module_names(): void
    {
        $doc = $this->document();
        $doc['synthetic'] = false;

        $plan = $this->plan($doc);

        self::assertSame('NO_PLATFORM_MODULE', $this->item('demo.priya.shah@example.test', 'demo-forklift-awareness', $plan, null)['reason']);
    }

    /** @param SnapshotDocument|null $doc */
    private function plan(?array $doc = null, ?TargetState $state = null): MigrationPlan
    {
        return (new MigrationPlanner())->plan(SourceSnapshot::fromArray($doc ?? $this->document()), SourceSnapshotTest::catalogue(), $state ?? $this->state());
    }

    /** @return SnapshotDocument */
    private function document(): array
    {
        return (new SyntheticSourceBuilder(SourceSnapshotTest::catalogue()))->build(new \DateTimeImmutable('2026-09-25T00:00:00Z'));
    }

    private function state(?string $extraAssignment = null, ?int $caseyUserId = null): TargetState
    {
        $accounts = [
            'demo.alex.ng@example.test' => ['userId' => 101, 'canSignIn' => false],
            'demo.priya.shah@example.test' => ['userId' => 102, 'canSignIn' => false],
            'demo.jordan.lee@example.test' => ['userId' => 103, 'canSignIn' => false],
            'hr.admin@example.test' => ['userId' => 2, 'canSignIn' => true],
        ];
        if ($caseyUserId !== null) {
            $accounts['demo.casey.new@example.test'] = ['userId' => $caseyUserId, 'canSignIn' => true];
        }
        $live = [101 => ['training:demo-site-induction']];
        if ($extraAssignment !== null) {
            $live[102] = [$extraAssignment];
        }

        return new TargetState(
            $accounts,
            [101 => ['publicId' => 'p-alex', 'departed' => false], 102 => ['publicId' => 'p-priya', 'departed' => false], 103 => ['publicId' => 'p-jordan', 'departed' => true]],
            $live,
            [],
            [],
        );
    }

    /**
     * The one item a person has for a platform slug (or, with $slug null in the
     * source mapping, the one whose platform slug could not be found).
     *
     * @return array<string, mixed>
     */
    private function item(string $email, string $slug, ?MigrationPlan $plan = null, ?string $matchSlug = 'same'): array
    {
        foreach (($plan ?? $this->plan())->people as $person) {
            if ($person['email'] !== $email) {
                continue;
            }
            foreach ((array) $person['items'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $itemSlug = $item['slug'] ?? null;
                if (($matchSlug === null && $itemSlug === null && ($item['kind'] ?? null) === 'training') || $itemSlug === $slug) {
                    /** @var array<string, mixed> $item */
                    return $item;
                }
            }
        }
        self::fail(sprintf('No %s item for %s', $slug, $email));
    }
}
