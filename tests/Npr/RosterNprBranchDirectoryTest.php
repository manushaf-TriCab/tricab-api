<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Npr;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Npr\Infrastructure\RosterNprBranchDirectory;

final class RosterNprBranchDirectoryTest extends TestCase
{
    public function test_branch_is_looked_up_by_email_ignoring_case(): void
    {
        $directory = new RosterNprBranchDirectory(NprFixtures::resource('sales-roster.json'));

        self::assertSame('SA/NT', $directory->branchForEmail('janek@tricab.com'));
        self::assertSame('SA/NT', $directory->branchForEmail('  JaneK@TriCab.com '));
    }

    public function test_salespeople_are_in_their_sales_territory(): void
    {
        $directory = new RosterNprBranchDirectory(NprFixtures::resource('sales-roster.json'));

        self::assertSame('VIC', $directory->branchForEmail('katherinef@tricab.com'));
        self::assertSame('TAS', $directory->branchForEmail('allisonmi@tricab.com'));
        self::assertSame('WA', $directory->branchForEmail('uygenp@tricab.com'));
        self::assertSame('NSW', $directory->branchForEmail('anu@tricab.com'));
        self::assertSame('QLD', $directory->branchForEmail('amandaw@tricab.com'));
        self::assertSame('NZ', $directory->branchForEmail('tiyanaj@tricab.com'));
    }

    public function test_the_seed_script_routes_each_territory_to_its_manager(): void
    {
        $seed = (string) file_get_contents(dirname(__DIR__, 2) . '/database/scripts/development/seed-npr-manager-routes.sql');
        $routes = [
            'VIC' => 'danielf@tricab.com',
            'TAS' => 'stuartj@tricab.com',
            'SA/NT' => 'neilo@tricab.com',
            'WA' => 'scotth@tricab.com',
            'NSW' => 'shanep@tricab.com',
            'QLD' => 'shanep@tricab.com',
            'NZ' => 'shanep@tricab.com',
        ];

        foreach ($routes as $territory => $manager) {
            self::assertMatchesRegularExpression(
                sprintf("/\\(N'%s',\\s+N'%s'/", preg_quote($territory, '/'), preg_quote($manager, '/')),
                $seed,
                "$territory goes to $manager",
            );
        }
    }

    public function test_someone_not_on_the_roster_is_unassigned(): void
    {
        $directory = new RosterNprBranchDirectory(NprFixtures::resource('sales-roster.json'));

        self::assertSame('unassigned', $directory->branchForEmail('nobody@example.com'));
    }

    public function test_a_missing_roster_file_leaves_everyone_unassigned(): void
    {
        self::assertSame('unassigned', (new RosterNprBranchDirectory('/nonexistent/roster.json'))->branchForEmail('janek@tricab.com'));
    }
}
