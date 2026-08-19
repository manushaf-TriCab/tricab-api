<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Module;

use TriCab\Core\LegacyMigration\Application\Exception\LegacyMigrationModuleNotFound;
use TriCab\Core\LegacyMigration\Contract\LegacyMigrationModule;
use TriCab\Core\LegacyMigration\Contract\LegacyMigrationModuleRegistry;
use TriCab\Core\LegacyMigration\Support\ModuleCatalog;

final class DefaultModuleRegistry implements LegacyMigrationModuleRegistry
{
    /** @var array<string, LegacyMigrationModule> */
    private array $modules = [];

    public function __construct(?LegacyMigrationModule $usersOverride = null)
    {
        foreach (ModuleCatalog::codesInDependencyOrder() as $code) {
            $this->register(FrameworkModule::harness($code));
        }
        if ($usersOverride !== null) {
            $this->register($usersOverride);
        }
    }

    public function register(LegacyMigrationModule $module): void
    {
        $this->modules[$module->code()] = $module;
    }

    public function get(string $code): LegacyMigrationModule
    {
        if (!isset($this->modules[$code])) {
            throw LegacyMigrationModuleNotFound::forCode($code);
        }

        return $this->modules[$code];
    }

    public function all(): array
    {
        $modules = array_values($this->modules);
        usort($modules, static fn (LegacyMigrationModule $a, LegacyMigrationModule $b): int => $a->sortOrder() <=> $b->sortOrder());

        return $modules;
    }

    public function has(string $code): bool
    {
        return isset($this->modules[$code]);
    }
}
