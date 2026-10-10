<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Ap\InMemoryApReferenceStore;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Core\Finance\Ap\Domain\ApConflict;
use TriCab\Core\Finance\Ap\Domain\ApInvalid;
use TriCab\Core\Finance\Ap\Domain\ApNotFound;
use TriCab\Core\Finance\Ap\Service\ApReferenceService;
use TriCab\Core\Finance\Ap\Service\ApTime;

/**
 * The three Settings lists on the in-memory twin: the source's keys, validation and messages
 * (`companies_*`, `cost_centres_*`, `account_categories_*`), and the few rules the platform adds.
 */
final class ApReferenceServiceTest extends TestCase
{
    private ApReferenceService $service;

    protected function setUp(): void
    {
        $this->service = new ApReferenceService(new InMemoryApReferenceStore(), new ApTime(FixedClock::utc('2026-10-09 01:02:03.456789'), 'Australia/Melbourne'));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function created(string $list, array $body): array
    {
        return $this->service->create($list, $body);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function id(array $row): string
    {
        self::assertIsString($row['id']);

        return $row['id'];
    }

    /**
     * @param class-string<\Throwable> $class
     */
    private function message(callable $work, string $class = ApInvalid::class): string
    {
        try {
            $work();
        } catch (\Throwable $failure) {
            self::assertInstanceOf($class, $failure);

            return $failure->getMessage();
        }
        self::fail('Expected the work to be refused.');
    }

    public function test_a_company_is_created_with_the_sources_keys_stripped_and_active_by_default(): void
    {
        $row = $this->created('companies', ['name' => '  TriCab Pty Ltd ', 'code' => ' TRI ', 'sites' => 'Somerton, Dandenong', 'former_names' => 'Old Name', 'ignored' => 'x']);

        self::assertSame(['id', 'code', 'name', 'sites', 'former_names', 'is_active', 'created_date'], array_keys($row));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', self::id($row));
        self::assertSame('TriCab Pty Ltd', $row['name']);
        self::assertSame('TRI', $row['code']);
        self::assertSame('Somerton, Dandenong', $row['sites']);
        self::assertTrue($row['is_active']);
        self::assertSame('2026-10-09T01:02:03.456789+00:00', $row['created_date']);
    }

    public function test_a_company_without_a_name_is_refused_in_the_sources_words(): void
    {
        self::assertSame('name is required', $this->message(fn () => $this->created('companies', ['code' => 'X'])));
        self::assertSame('name is required', $this->message(fn () => $this->created('companies', ['name' => '   '])));
        self::assertSame('name is required', $this->message(fn () => $this->created('companies', ['name' => ['x']])), 'a non-text name reads as empty');
    }

    public function test_a_cost_centre_needs_a_code_and_keeps_code_no_and_name_stripped(): void
    {
        self::assertSame('code is required', $this->message(fn () => $this->created('cost-centres', ['name' => 'No code'])));

        $row = $this->created('cost-centres', ['code' => ' CC100 ', 'code_no' => ' 100 ', 'name' => ' Warehouse ', 'is_active' => false]);

        self::assertSame(['id', 'code_no', 'code', 'name', 'is_active', 'created_date'], array_keys($row));
        self::assertSame(['CC100', '100', 'Warehouse', false], [$row['code'], $row['code_no'], $row['name'], $row['is_active']]);
    }

    public function test_an_account_category_needs_a_code(): void
    {
        self::assertSame('code is required', $this->message(fn () => $this->created('account-categories', [])));

        $row = $this->created('account-categories', ['code' => 'FREIGHT', 'name' => 'Freight']);

        self::assertSame(['id', 'code', 'name', 'is_active', 'created_date'], array_keys($row));
    }

    public function test_a_duplicate_of_the_unique_column_is_a_409_ignoring_case(): void
    {
        $this->created('companies', ['name' => 'TriCab']);
        $this->created('cost-centres', ['code' => 'CC1']);

        self::assertSame('A company with that name already exists', $this->message(fn () => $this->created('companies', ['name' => 'TRICAB']), ApConflict::class));
        self::assertSame('A cost centre with that code already exists', $this->message(fn () => $this->created('cost-centres', ['code' => 'cc1']), ApConflict::class));
        $this->created('companies', ['name' => 'Other', 'code' => 'TriCab']);
        $this->created('account-categories', ['code' => 'F']);
        self::assertSame('An account category with that code already exists', $this->message(fn () => $this->created('account-categories', ['code' => 'f']), ApConflict::class));
    }

    public function test_a_value_too_long_for_its_column_is_refused(): void
    {
        self::assertSame('The name must be at most 200 characters', $this->message(fn () => $this->created('companies', ['name' => str_repeat('x', 201)])));
        self::assertSame('The former names must be at most 2000 characters', $this->message(fn () => $this->created('companies', ['name' => 'A', 'former_names' => str_repeat('x', 2001)])));
        $this->created('companies', ['name' => str_repeat('é', 200)]);
    }

    public function test_an_unknown_list_is_not_found(): void
    {
        $this->message(fn () => $this->service->list('nonsense'), ApNotFound::class);
    }

    public function test_a_patch_changes_only_the_keys_it_carries(): void
    {
        $row = $this->created('companies', ['name' => 'TriCab', 'code' => 'TRI', 'sites' => 'Somerton']);
        $id = self::id($row);

        $patched = $this->service->update('companies', $id, ['sites' => 'Somerton, Altona', 'is_active' => 0, 'nonsense' => 1]);

        self::assertSame('TriCab', $patched['name']);
        self::assertSame('TRI', $patched['code']);
        self::assertSame('Somerton, Altona', $patched['sites']);
        self::assertFalse($patched['is_active']);
        self::assertSame($row['created_date'], $patched['created_date']);
        self::assertSame($patched, $this->service->list('companies')[0]);

        self::assertSame($patched, $this->service->update('companies', $id, []), 'an empty patch answers the row as it is');
    }

    public function test_a_patch_of_an_unknown_id_is_not_found_and_may_not_blank_the_required_field_or_clash(): void
    {
        $this->message(fn () => $this->service->update('companies', str_repeat('0', 32), ['name' => 'X']), ApNotFound::class);

        $a = $this->created('cost-centres', ['code' => 'A']);
        $this->created('cost-centres', ['code' => 'B']);
        self::assertSame('code is required', $this->message(fn () => $this->service->update('cost-centres', self::id($a), ['code' => ' '])));
        self::assertSame('A cost centre with that code already exists', $this->message(fn () => $this->service->update('cost-centres', self::id($a), ['code' => 'b']), ApConflict::class));
        $patched = $this->service->update('cost-centres', self::id($a), ['name' => '']);
        self::assertSame('', $patched['name']);
    }

    public function test_a_delete_answers_ok_whether_or_not_the_row_was_there(): void
    {
        $row = $this->created('account-categories', ['code' => 'X']);

        self::assertSame(['ok' => true], $this->service->delete('account-categories', self::id($row)));
        self::assertSame([], $this->service->list('account-categories'));
        self::assertSame(['ok' => true], $this->service->delete('account-categories', self::id($row)));
    }

    public function test_lists_sort_on_the_named_column_either_way_and_fall_back_on_anything_else(): void
    {
        foreach (['Charlie', 'alpha', 'Bravo'] as $name) {
            $this->created('companies', ['name' => $name, 'sites' => 'z' . $name]);
        }

        self::assertSame(['alpha', 'Bravo', 'Charlie'], array_column($this->service->list('companies', 'name'), 'name'));
        self::assertSame(['Charlie', 'Bravo', 'alpha'], array_column($this->service->list('companies', '-name'), 'name'));
        // Long text (`sites`), a column the list does not have, and nothing at all fall back to creation order.
        $created = ['Charlie', 'alpha', 'Bravo'];
        self::assertSame(count($created), count($this->service->list('companies', 'sites')));
        self::assertSame(count($created), count($this->service->list('companies', 'nonsense')));
        self::assertSame(count($created), count($this->service->list('companies', ['x'])));
        self::assertSame(count($created), count($this->service->list('companies', null, 'abc')));
    }

    public function test_the_limit_defaults_to_200_and_is_held_between_1_and_1000(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->created('account-categories', ['code' => 'C' . $i]);
        }

        self::assertCount(5, $this->service->list('account-categories'));
        self::assertCount(2, $this->service->list('account-categories', 'code', '2'));
        self::assertCount(1, $this->service->list('account-categories', 'code', '0'));
        self::assertCount(1, $this->service->list('account-categories', 'code', '-5'));
        self::assertCount(5, $this->service->list('account-categories', 'code', '99999'));
    }
}
