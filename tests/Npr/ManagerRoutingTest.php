<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Npr;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Tests\Npr\Support\NprWorkflowWorld as W;
use TriCab\Core\Npr\Domain\Exception\InvalidNprInput;
use TriCab\Core\Npr\Domain\Exception\NprWorkflowRefused;

/** Manager routing (plan N2.1): branch routes, dated delegate overrides, and overlap refusal. */
final class ManagerRoutingTest extends TestCase
{
    private static function day(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date, new DateTimeZone('UTC'));
    }

    public function test_a_branch_resolves_to_its_route_in_any_case(): void
    {
        $w = new W();

        self::assertSame(W::SHANE, $w->routing->resolve('Brisbane')?->manager->userId);
        self::assertSame(W::SHANE, $w->routing->resolve('  brisbane ')?->manager->userId);
        self::assertNull($w->routing->resolve('Perth'), 'no confirmed route');
        self::assertNull($w->routing->resolve('unassigned'));
        self::assertNull($w->routing->resolve(''));
    }

    public function test_a_route_applies_only_inside_its_dates(): void
    {
        $w = new W(brisbaneRouted: false);
        $w->route('Perth', W::SHANE, from: '2026-11-01', to: '2026-11-30');

        self::assertNull($w->routing->resolve('Perth', self::day('2026-10-31')));
        self::assertNotNull($w->routing->resolve('Perth', self::day('2026-11-01')), 'first day included');
        self::assertNotNull($w->routing->resolve('Perth', self::day('2026-11-30')), 'last day included');
        self::assertNull($w->routing->resolve('Perth', self::day('2026-12-01')));
    }

    public function test_an_active_delegate_override_wins_and_expires(): void
    {
        $w = new W();
        $w->route('Brisbane', W::OTHER_MANAGER, delegate: true, from: '2026-10-20', to: '2026-10-25');

        self::assertSame(W::SHANE, $w->routing->resolve('Brisbane', self::day('2026-10-19'))?->manager->userId, 'before the override');
        $delegate = $w->routing->resolve('Brisbane', self::day('2026-10-22'));
        self::assertSame(W::OTHER_MANAGER, $delegate?->manager->userId);
        self::assertTrue($delegate->isDelegate);
        self::assertSame(W::SHANE, $w->routing->resolve('Brisbane', self::day('2026-10-26'))?->manager->userId, 'after the override');
    }

    public function test_an_override_decides_who_a_submission_goes_to(): void
    {
        $w = new W();
        $w->route('Brisbane', W::OTHER_MANAGER, delegate: true, from: '2026-10-01');
        [$id, $version] = $w->completeDraft();

        $view = $w->workflow->submit($w->user(W::SALES), $id, $version);

        self::assertSame(W::OTHER_MANAGER, $view?->request->manager?->userId);
        self::assertNull($w->workflow->getForManager($w->user(W::SHANE), $id), 'the regular manager is bypassed while the override runs');
    }

    public function test_overlapping_routes_of_one_kind_are_refused(): void
    {
        $w = new W();

        foreach ([['2026-06-01', null], ['2025-01-01', '2026-02-01'], ['2026-03-01', '2026-03-31']] as [$from, $to]) {
            try {
                $w->route('brisbane', W::OTHER_MANAGER, from: $from, to: $to);
                self::fail("Expected an overlap for $from.");
            } catch (NprWorkflowRefused $refused) {
                self::assertSame('NPR_ROUTE_OVERLAP', $refused->reason);
            }
        }
        $w->route('Perth', W::OTHER_MANAGER);
        self::assertCount(2, $w->routes->all(), 'nothing was added by the refusals');
    }

    public function test_back_to_back_routes_are_fine_and_a_delegate_may_overlap_the_branch_route(): void
    {
        $w = new W(brisbaneRouted: false);
        $w->route('Brisbane', W::SHANE, to: '2026-06-30');
        $w->route('Brisbane', W::OTHER_MANAGER, from: '2026-07-01');
        $w->route('Brisbane', W::TSD, delegate: true, from: '2026-06-01', to: '2026-08-01');

        self::assertCount(3, $w->routes->all());
    }

    public function test_ending_a_route_can_be_undone_only_if_it_does_not_overlap(): void
    {
        $w = new W(brisbaneRouted: false);
        $w->route('Brisbane', W::SHANE, to: '2026-06-30');
        $w->route('Brisbane', W::OTHER_MANAGER, from: '2026-07-01');

        $this->expectException(NprWorkflowRefused::class);
        $w->routes->setValidTo(1, null);
    }

    public function test_admin_creates_lists_and_ends_a_route(): void
    {
        $w = new W(brisbaneRouted: false);
        $admin = $w->user(W::SALES);

        $created = $w->routing->create($admin, ['branch' => 'Brisbane', 'managerEmail' => 'SHANEP@tricab.com', 'validFrom' => '2026-10-01']);
        self::assertSame(W::SHANE, $created->manager->userId);
        self::assertSame(W::SALES, $created->approvedByUserId);
        self::assertFalse($created->isDelegate);
        self::assertNull($created->validTo);

        $ended = $w->routing->update($admin, $created->id, ['validTo' => '2026-12-31']);
        self::assertNotNull($ended);
        self::assertSame('2026-12-31', $ended[0]->validTo?->format('Y-m-d'));
        self::assertNull($w->routing->update($admin, 999, ['validTo' => '2026-12-31']));
        self::assertCount(1, $w->routing->list());

        try {
            $w->routing->create($admin, ['branch' => 'Brisbane', 'managerUserId' => W::OTHER_MANAGER, 'validFrom' => '2026-12-01']);
            self::fail('Expected an overlap.');
        } catch (NprWorkflowRefused $refused) {
            self::assertSame('NPR_ROUTE_OVERLAP', $refused->reason);
        }
    }

    public function test_replacing_a_manager_ends_the_old_route_the_day_before(): void
    {
        $w = new W(brisbaneRouted: false);
        $admin = $w->user(W::SALES);
        $old = $w->routing->create($admin, ['branch' => 'Brisbane', 'managerUserId' => W::SHANE, 'validFrom' => '2026-01-01']);

        $changed = $w->routing->update($admin, $old->id, ['replacement' => ['managerUserId' => W::OTHER_MANAGER, 'validFrom' => '2026-11-01']]);

        self::assertNotNull($changed);
        self::assertCount(2, $changed);
        self::assertSame('2026-10-31', $changed[0]->validTo?->format('Y-m-d'));
        self::assertSame(W::OTHER_MANAGER, $changed[1]->manager->userId);
        self::assertNull($changed[1]->validTo);
        self::assertSame(W::SHANE, $w->routing->resolve('Brisbane', self::day('2026-10-31'))?->manager->userId);
        self::assertSame(W::OTHER_MANAGER, $w->routing->resolve('Brisbane', self::day('2026-11-01'))?->manager->userId);
    }

    public function test_a_replacement_that_overlaps_another_route_changes_nothing(): void
    {
        $w = new W(brisbaneRouted: false);
        $admin = $w->user(W::SALES);
        $first = $w->routing->create($admin, ['branch' => 'Brisbane', 'managerUserId' => W::SHANE, 'validFrom' => '2026-01-01', 'validTo' => '2026-03-31']);
        $w->routing->create($admin, ['branch' => 'Brisbane', 'managerUserId' => W::OTHER_MANAGER, 'validFrom' => '2026-06-01']);

        try {
            $w->routing->update($admin, $first->id, ['replacement' => ['managerUserId' => W::TSD, 'validFrom' => '2026-04-01']]);
            self::fail('Expected an overlap.');
        } catch (NprWorkflowRefused $refused) {
            self::assertSame('NPR_ROUTE_OVERLAP', $refused->reason);
        }
        self::assertCount(2, $w->routes->all());
        self::assertSame('2026-03-31', $w->routes->find($first->id)?->validTo?->format('Y-m-d'), 'the old route is untouched');
    }

    public function test_bad_route_input_is_refused(): void
    {
        $w = new W(brisbaneRouted: false);
        $admin = $w->user(W::SALES);

        $bad = [
            ['managerEmail' => 'shanep@tricab.com'],
            ['branch' => 'unassigned', 'managerEmail' => 'shanep@tricab.com'],
            ['branch' => 'Perth'],
            ['branch' => 'Perth', 'managerEmail' => 'nobody@tricab.com'],
            ['branch' => 'Perth', 'managerEmail' => 'shanep@tricab.com', 'validFrom' => '1 Nov'],
            ['branch' => 'Perth', 'managerEmail' => 'shanep@tricab.com', 'validFrom' => '2026-11-02', 'validTo' => '2026-11-01'],
        ];
        foreach ($bad as $body) {
            try {
                $w->routing->create($admin, $body);
                self::fail('Expected InvalidNprInput for ' . json_encode($body));
            } catch (InvalidNprInput) {
                self::assertSame([], $w->routes->all());
            }
        }

        $w->directory->add(W::TSD_TWO, 'tsdtwo@tricab.com', 'TSD Two', [], active: false);
        $this->expectException(InvalidNprInput::class);
        $w->routing->create($admin, ['branch' => 'Perth', 'managerUserId' => W::TSD_TWO]);
    }
}
