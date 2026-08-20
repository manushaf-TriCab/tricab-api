<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\LegacyMigration;

use PHPUnit\Framework\TestCase;
use TriCab\Core\LegacyMigration\Live\MysqlGrantInspector;

final class MysqlGrantInspectorTest extends TestCase
{
    public function test_select_only_is_allowed(): void
    {
        $inspector = new MysqlGrantInspector();
        self::assertTrue($inspector->isSelectOnly([
            ['Grants for u@h' => 'GRANT USAGE ON *.* TO `u`@`%`'],
            ['Grants for u@h' => 'GRANT SELECT ON `tricab`.* TO `u`@`%`'],
            ['Grants for u@h' => 'GRANT SELECT ON `tc`.* TO `u`@`%`'],
        ]));
    }

    public function test_write_grants_are_blocked(): void
    {
        $inspector = new MysqlGrantInspector();
        self::assertFalse($inspector->isSelectOnly([
            ['GRANT' => 'GRANT SELECT, INSERT ON `tricab`.* TO `u`@`%`'],
        ]));
        self::assertFalse($inspector->isSelectOnly([
            ['GRANT' => 'GRANT ALL PRIVILEGES ON `tc`.* TO `u`@`%`'],
        ]));
    }

    public function test_empty_or_usage_only_is_blocked(): void
    {
        $inspector = new MysqlGrantInspector();
        self::assertFalse($inspector->isSelectOnly([]));
        self::assertFalse($inspector->isSelectOnly([
            ['GRANT' => 'GRANT USAGE ON *.* TO `u`@`%`'],
        ]));
    }
}
