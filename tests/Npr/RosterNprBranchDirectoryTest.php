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

        self::assertSame('Adelaide', $directory->branchForEmail('janek@tricab.com'));
        self::assertSame('Adelaide', $directory->branchForEmail('  JaneK@TriCab.com '));
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
