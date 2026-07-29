<?php

declare(strict_types=1);

namespace TriCab\Core\Tests;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Module;
use TriCab\Core\Shared\Package;

final class PackageSmokeTest extends TestCase
{
    public function test_package_marker_exposes_composer_name(): void
    {
        self::assertSame('tricab/tricab-core', Package::NAME);
    }

    public function test_identity_module_marker_exposes_name(): void
    {
        self::assertSame('Identity', Module::NAME);
    }
}
