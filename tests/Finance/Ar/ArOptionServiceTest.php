<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Ar\InMemoryArOptionStore;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Core\Finance\Ar\Domain\ArInvalid;
use TriCab\Core\Finance\Ar\Service\ArOptionService;
use TriCab\Core\Finance\Ar\Service\ArRules;
use TriCab\Core\Finance\Ar\Service\ArTime;

/**
 * The Category and Method lists on the in-memory twin: every rule of `store.py` (`_seed_options` to
 * `reorder_options`) and the source's tests 13a to 13d, with the source's messages.
 */
final class ArOptionServiceTest extends TestCase
{
    private const CATEGORIES = [
        'remittance_advice', 'credit_application', 'release_customer_orders', 'exceeding_credit_limits',
        'invoice_statement_requests', 'credit_notes', 'overdue_collections', 'compliance_questionnaire',
        'internal_sales_query', 'marketing_no_action', 'general_inquiry', 'other',
    ];

    private CountingArOptionStore $store;

    private ArOptionService $service;

    protected function setUp(): void
    {
        $this->store = new CountingArOptionStore();
        $this->service = new ArOptionService($this->store, new ArTime(FixedClock::utc('2026-10-08 01:02:03'), 'Australia/Melbourne'));
    }

    /**
     * @param array<string, list<array<string, mixed>>> $lists
     * @return list<string>
     */
    private static function values(array $lists, string $name): array
    {
        $out = [];
        foreach ($lists[$name] as $option) {
            self::assertIsString($option['value']);
            $out[] = $option['value'];
        }

        return $out;
    }

    private function message(callable $work): string
    {
        try {
            $work();
        } catch (ArInvalid $failure) {
            return $failure->getMessage();
        }
        self::fail('Expected the work to be refused.');
    }

    public function test_an_empty_install_is_seeded_with_twelve_categories_and_two_methods(): void
    {
        $lists = $this->service->lists();

        self::assertSame(self::CATEGORIES, self::values($lists, 'category'));
        self::assertSame(['phone', 'email'], self::values($lists, 'method'));
        self::assertSame('other', $lists['category'][11]['value']);
        self::assertSame(range(0, 11), array_column($lists['category'], 'sort_order'));
        foreach ($lists['category'] as $option) {
            self::assertTrue($option['active']);
            self::assertIsString($option['value']);
            self::assertSame($option['value'] === 'other', $option['is_system'], $option['value']);
            self::assertSame(0, $option['usage_count']);
        }
        self::assertSame([true, true], array_column($lists['method'], 'is_system'));
        // The first category's long description is the source's, joined.
        $description = $lists['category'][0]['description'];
        self::assertIsString($description);
        self::assertStringStartsWith('Notification that a payment has been made or scheduled: remittance advice, EFT advice', $description);
        self::assertSame('teal', $lists['category'][0]['color']);
        self::assertSame('Remittance / Payment', $lists['category'][0]['label']);
    }

    public function test_seeding_twice_changes_nothing_and_a_list_with_rows_is_left_alone(): void
    {
        $this->service->lists();
        $this->service->update('method', 'phone', ['label' => 'Phone call']);
        $this->service->delete('category', 'general_inquiry');

        $lists = $this->service->lists();

        self::assertSame('Phone call', $lists['method'][0]['label'], 'the Settings page edit survives');
        self::assertCount(11, $lists['category'], 'a removed default is not put back');
    }

    public function test_usage_counts_come_from_the_records(): void
    {
        $this->store->counts = ['category' => ['other' => 4, 'credit_notes' => 1], 'method' => ['email' => 9]];

        $lists = $this->service->lists();

        $byValue = array_column($lists['category'], 'usage_count', 'value');
        self::assertSame(4, $byValue['other']);
        self::assertSame(1, $byValue['credit_notes']);
        self::assertSame(0, $byValue['marketing_no_action']);
        self::assertSame([0, 9], array_column($lists['method'], 'usage_count'));
    }

    public function test_a_new_option_takes_its_slug_and_goes_above_other(): void
    {
        $created = $this->service->create('category', ['label' => ' Warranty Claim ', 'description' => ' Customer claiming warranty on a delivered cable ', 'color' => 'orange']);

        self::assertSame('warranty_claim', $created['value']);
        self::assertSame('Warranty Claim', $created['label']);
        self::assertSame('Customer claiming warranty on a delivered cable', $created['description']);
        self::assertSame('orange', $created['color']);
        self::assertSame(11, $created['sort_order']);
        self::assertTrue($created['active']);
        self::assertFalse($created['is_system']);
        $values = self::values($this->service->lists(), 'category');
        self::assertSame('warranty_claim', $values[11]);
        self::assertSame('other', $values[12], 'new options go above Other');
        self::assertSame(range(0, 12), array_column($this->service->lists()['category'], 'sort_order'));
    }

    public function test_a_new_method_goes_at_the_end_because_there_is_no_other(): void
    {
        $created = $this->service->create('method', ['label' => 'Teams Message']);

        self::assertSame('teams_message', $created['value']);
        self::assertSame(2, $created['sort_order']);
        self::assertSame('slate', $created['color'], 'the default colour');
        self::assertSame('', $created['description']);
    }

    #[DataProvider('slugs')]
    public function test_the_slug_is_lower_case_underscored_and_cut_to_48(string $label, string $expected): void
    {
        self::assertSame($expected, ArOptionService::slug($label));
    }

    /** @return iterable<string, array{string, string}> */
    public static function slugs(): iterable
    {
        yield 'spaces and caps' => ['Credit Limit / Terms', 'credit_limit_terms'];
        yield 'edges trimmed' => ['  --Hello--  ', 'hello'];
        yield 'digits kept' => ['Net 30 days', 'net_30_days'];
        yield 'only symbols' => ['!!!', ''];
        yield 'cut at 48' => [str_repeat('a', 60), str_repeat('a', 48)];
        yield 'accents are not letters here' => ['Café', 'caf'];
    }

    public function test_a_clashing_value_gets_a_numbered_suffix_against_every_option_active_or_not(): void
    {
        $first = $this->service->create('category', ['label' => 'Warranty Claim']);
        self::assertIsString($first['value']);
        $this->service->update('category', $first['value'], ['active' => false]);

        $second = $this->service->create('category', ['label' => 'Warranty claim']);
        self::assertIsString($second['value']);
        $this->service->update('category', $second['value'], ['active' => false]);
        $third = $this->service->create('category', ['label' => 'WARRANTY CLAIM']);

        self::assertSame('warranty_claim_2', $second['value']);
        self::assertSame('warranty_claim_3', $third['value']);
    }

    public function test_a_label_already_in_the_list_is_refused_in_any_case_but_an_archived_one_does_not_block(): void
    {
        // The message quotes what was typed, trimmed, as the source's `{label!r}` does.
        self::assertSame("'other' is already in the list", $this->message(fn () => $this->service->create('category', ['label' => 'other'])));
        self::assertSame("'PHONE' is already in the list", $this->message(fn () => $this->service->create('method', ['label' => ' PHONE '])));

        $this->service->create('method', ['label' => 'Letter']);
        $this->service->update('method', 'letter', ['active' => false]);
        $again = $this->service->create('method', ['label' => 'Letter']);
        self::assertSame('letter_2', $again['value']);
    }

    public function test_the_refusals_on_create_use_the_sources_words(): void
    {
        self::assertSame('A name is required', $this->message(fn () => $this->service->create('category', [])));
        self::assertSame('A name is required', $this->message(fn () => $this->service->create('category', ['label' => '   '])));
        self::assertSame('A name is required', $this->message(fn () => $this->service->create('category', ['label' => ['x']])));
        self::assertSame('The name needs at least one letter or number', $this->message(fn () => $this->service->create('category', ['label' => '!!!'])));
        self::assertSame('The name must be at most 200 characters', $this->message(fn () => $this->service->create('category', ['label' => str_repeat('a', 201)])));
        self::assertSame('The description must be at most 2000 characters', $this->message(fn () => $this->service->create('category', ['label' => 'Ok', 'description' => str_repeat('d', 2001)])));
        self::assertSame('The colour must be at most 20 characters', $this->message(fn () => $this->service->create('category', ['label' => 'Ok', 'color' => str_repeat('c', 21)])));
    }

    #[DataProvider('operations')]
    public function test_an_unknown_list_is_refused_by_every_operation(string $operation): void
    {
        $call = match ($operation) {
            'create' => fn () => $this->service->create('nonsense', ['label' => 'X']),
            'update' => fn () => $this->service->update('nonsense', 'x', ['label' => 'X']),
            'delete' => fn () => $this->service->delete('nonsense', 'x'),
            default => fn () => $this->service->reorder('nonsense', ['x']),
        };

        self::assertSame("Unknown list: 'nonsense'", $this->message($call));
    }

    /** @return iterable<string, array{string}> */
    public static function operations(): iterable
    {
        yield 'create' => ['create'];
        yield 'update' => ['update'];
        yield 'delete' => ['delete'];
        yield 'reorder' => ['reorder'];
    }

    public function test_a_built_in_can_be_renamed_but_not_archived_or_removed(): void
    {
        $renamed = $this->service->update('method', 'phone', ['label' => 'Phone call']);
        self::assertSame('Phone call', $renamed['label']);

        self::assertSame("'Phone call' is built in and cannot be archived", $this->message(fn () => $this->service->update('method', 'phone', ['active' => false])));
        self::assertSame("'Other' is built in and cannot be removed", $this->message(fn () => $this->service->delete('category', 'other')));
        self::assertSame("'Email' is built in and cannot be removed", $this->message(fn () => $this->service->delete('method', 'email')));
        self::assertSame("'Other' is built in and cannot be archived", $this->message(fn () => $this->service->update('category', 'other', ['active' => 0])));
        self::assertSame("'Other' is built in and cannot be archived", $this->message(fn () => $this->service->update('category', 'other', ['active' => null])));
    }

    public function test_the_eleven_other_categories_are_not_built_in(): void
    {
        $result = $this->service->update('category', 'marketing_no_action', ['active' => false]);
        self::assertFalse($result['active']);

        self::assertSame(['deleted' => true, 'archived' => false, 'usage_count' => 0], $this->service->delete('category', 'general_inquiry'));
    }

    public function test_update_changes_only_what_is_sent_and_reads_active_as_python_does(): void
    {
        $this->service->create('category', ['label' => 'Warranty Claim', 'color' => 'orange']);

        $one = $this->service->update('category', 'warranty_claim', ['description' => '  New criterion  ']);
        self::assertSame('New criterion', $one['description']);
        self::assertSame('Warranty Claim', $one['label']);
        self::assertSame('orange', $one['color']);

        self::assertSame('slate', $this->service->update('category', 'warranty_claim', ['color' => ''])['color']);
        self::assertFalse($this->service->update('category', 'warranty_claim', ['active' => 0])['active']);
        self::assertTrue($this->service->update('category', 'warranty_claim', ['active' => 1])['active']);
        self::assertTrue($this->service->update('category', 'warranty_claim', ['active' => 'false'])['active'], 'a non-empty string is true, as in Python');
        self::assertFalse($this->service->update('category', 'warranty_claim', ['active' => ''])['active']);
        self::assertSame('Warranty Claim', $this->service->update('category', 'warranty_claim', [])['label']);
    }

    public function test_the_refusals_on_update_use_the_sources_words(): void
    {
        self::assertSame('Option not found', $this->message(fn () => $this->service->update('category', 'nope', ['label' => 'X'])));
        self::assertSame('A name is required', $this->message(fn () => $this->service->update('category', 'credit_notes', ['label' => ' '])));
        self::assertSame('The name must be at most 200 characters', $this->message(fn () => $this->service->update('category', 'credit_notes', ['label' => str_repeat('a', 201)])));
    }

    public function test_a_rename_is_not_checked_against_the_other_labels(): void
    {
        $renamed = $this->service->update('category', 'credit_notes', ['label' => 'Other']);

        self::assertSame('Other', $renamed['label'], 'the source checks a duplicate label on create only');
    }

    public function test_a_used_option_is_archived_and_an_unused_one_deleted(): void
    {
        $this->service->create('method', ['label' => 'Teams Message']);
        $this->store->counts = ['method' => ['teams_message' => 3]];

        self::assertSame(['deleted' => false, 'archived' => true, 'usage_count' => 3], $this->service->delete('method', 'teams_message'));
        $archived = array_values(array_filter($this->service->lists()['method'], static fn (array $o): bool => $o['value'] === 'teams_message'));
        self::assertFalse($archived[0]['active']);

        $this->service->create('method', ['label' => 'Letter']);
        self::assertSame(['deleted' => true, 'archived' => false, 'usage_count' => 0], $this->service->delete('method', 'letter'));
        self::assertNotContains('letter', self::values($this->service->lists(), 'method'));
        self::assertSame('Option not found', $this->message(fn () => $this->service->delete('method', 'letter')));
    }

    public function test_only_active_options_can_be_newly_assigned(): void
    {
        $this->service->create('category', ['label' => 'Warranty Claim']);
        $this->service->update('category', 'warranty_claim', ['active' => false]);

        $active = $this->service->activeValues('category');

        self::assertNotContains('warranty_claim', $active);
        self::assertContains('other', $active);
        self::assertCount(12, $active);
        self::assertSame(['phone', 'email'], $this->service->activeValues('method'));
    }

    public function test_reorder_numbers_the_options_in_the_order_given(): void
    {
        $this->service->create('method', ['label' => 'Letter']);

        $result = $this->service->reorder('method', ['letter', 'email', 'phone']);

        self::assertSame(['letter', 'email', 'phone'], array_column($result, 'value'));
        self::assertSame([0, 1, 2], array_column($result, 'sort_order'));
        self::assertSame(['letter', 'email', 'phone'], self::values($this->service->lists(), 'method'));
    }

    public function test_reorder_must_list_every_option_exactly_once_archived_ones_included(): void
    {
        $this->service->create('method', ['label' => 'Letter']);
        $this->service->update('method', 'letter', ['active' => false]);
        $words = 'The new order must list every option exactly once';

        self::assertSame($words, $this->message(fn () => $this->service->reorder('method', ['phone', 'email'])), 'the archived one is missing');
        self::assertSame($words, $this->message(fn () => $this->service->reorder('method', ['phone', 'email', 'letter', 'letter'])));
        self::assertSame($words, $this->message(fn () => $this->service->reorder('method', ['phone', 'email', 'fax'])));
        self::assertSame($words, $this->message(fn () => $this->service->reorder('method', ['phone', 'email', 3])));
        self::assertSame($words, $this->message(fn () => $this->service->reorder('method', 'phone')));
        self::assertSame($words, $this->message(fn () => $this->service->reorder('method', null)));
        self::assertSame(['phone', 'email', 'letter'], self::values($this->service->lists(), 'method'), 'nothing was reordered');
        self::assertCount(3, $this->service->reorder('method', ['email', 'letter', 'phone']));
    }

    public function test_a_failed_create_leaves_the_orders_as_they_were(): void
    {
        $before = array_column($this->service->lists()['category'], 'sort_order', 'value');
        $this->store->failNextInsert = true;

        try {
            $this->service->create('category', ['label' => 'Warranty Claim']);
            self::fail('The insert was made to fail.');
        } catch (\RuntimeException) {
        }

        self::assertSame($before, array_column($this->service->lists()['category'], 'sort_order', 'value'), 'the shift was rolled back with the failed insert');
    }

    public function test_python_repr_quotes_like_the_sources_messages(): void
    {
        self::assertSame("'Other'", ArRules::repr('Other'));
        self::assertSame('"Won\'t"', ArRules::repr("Won't"));
        self::assertSame("'a\\\\b'", ArRules::repr('a\\b'));
    }

    public function test_timestamps_leave_with_an_offset(): void
    {
        $created = $this->service->create('method', ['label' => 'Letter']);

        self::assertSame('2026-10-08T01:02:03.000000+00:00', $created['created_date']);
        self::assertSame($created['created_date'], $created['updated_date']);
    }
}

/**
 * The twin with usage counts a test sets, and an insert that can be made to fail.
 *
 * @internal
 */
final class CountingArOptionStore extends InMemoryArOptionStore
{
    /** @var array<string, array<string, int>> */
    public array $counts = [];

    public bool $failNextInsert = false;

    protected function usageCounts(string $listName): array
    {
        return $this->counts[$listName] ?? [];
    }

    public function insertOption(\TriCab\Core\Finance\Ar\Domain\ArOption $option): void
    {
        if ($this->failNextInsert && $option->label === 'Warranty Claim') {
            $this->failNextInsert = false;
            throw new \RuntimeException('disk full');
        }
        parent::insertOption($option);
    }
}
