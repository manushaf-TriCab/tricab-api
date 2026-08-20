<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Module;

use TriCab\Core\LegacyMigration\Contract\Executor;
use TriCab\Core\LegacyMigration\Contract\Extractor;
use TriCab\Core\LegacyMigration\Contract\LegacyMigrationModule;
use TriCab\Core\LegacyMigration\Contract\Mapper;
use TriCab\Core\LegacyMigration\Contract\Normalizer;
use TriCab\Core\LegacyMigration\Contract\Validator;
use TriCab\Core\LegacyMigration\Contract\Verifier;
use TriCab\Core\LegacyMigration\Contract\ExistingNormalizedEmailLookup;
use TriCab\Core\LegacyMigration\Plugin\CanonicalNormalizer;
use TriCab\Core\LegacyMigration\Plugin\DuplicateIdentityValidator;
use TriCab\Core\LegacyMigration\Plugin\FixtureExtractor;
use TriCab\Core\LegacyMigration\Plugin\NullExistingNormalizedEmailLookup;
use TriCab\Core\LegacyMigration\Plugin\RequiredFieldValidator;
use TriCab\Core\LegacyMigration\Plugin\SnapshotVerifier;
use TriCab\Core\LegacyMigration\Plugin\StagingMapper;
use TriCab\Core\LegacyMigration\Plugin\StagingOnlyExecutor;
use TriCab\Core\LegacyMigration\Plugin\UserContactValidator;
use TriCab\Core\LegacyMigration\Plugin\UserEmailValidator;
use TriCab\Core\LegacyMigration\Plugin\UserEntraValidator;
use TriCab\Core\LegacyMigration\Plugin\UserRoleValidator;
use TriCab\Core\LegacyMigration\Plugin\UserStaffValidator;
use TriCab\Core\LegacyMigration\Support\ModuleCatalog;

/**
 * Open/Closed registration unit. Future real modules replace plugins without changing the engine.
 */
final class FrameworkModule implements LegacyMigrationModule
{
    /**
     * @param list<Validator> $validators
     */
    public function __construct(
        private readonly string $code,
        private readonly string $label,
        private readonly string $sourceSystem,
        private readonly int $sortOrder,
        private readonly Extractor $extractor,
        private readonly Normalizer $normalizer,
        private readonly array $validators,
        private readonly Mapper $mapper,
        private readonly Executor $executor,
        private readonly Verifier $verifier,
        private readonly bool $implemented = false,
    ) {
    }

    public static function harness(string $code): self
    {
        $meta = null;
        foreach (ModuleCatalog::modules() as $row) {
            if ($row['code'] === $code) {
                $meta = $row;
                break;
            }
        }
        if ($meta === null) {
            throw new \InvalidArgumentException('Unknown module: ' . $code);
        }

        $fixtures = self::fixturesFor($code);
        $required = $code === ModuleCatalog::USERS ? ['legacyId', 'email'] : ['legacyId'];

        return new self(
            code: $meta['code'],
            label: $meta['label'],
            sourceSystem: $meta['sourceSystem'],
            sortOrder: $meta['sortOrder'],
            extractor: new FixtureExtractor($code, ModuleCatalog::rawTable($code), $fixtures),
            normalizer: new CanonicalNormalizer($code),
            validators: [
                new RequiredFieldValidator($required),
                new DuplicateIdentityValidator($code === ModuleCatalog::USERS ? 'email' : 'legacyId'),
            ],
            mapper: new StagingMapper(),
            executor: new StagingOnlyExecutor(),
            verifier: new SnapshotVerifier(),
            implemented: false,
        );
    }

    public static function usersLive(Extractor $extractor, ?ExistingNormalizedEmailLookup $emails = null): self
    {
        $meta = null;
        foreach (ModuleCatalog::modules() as $row) {
            if ($row['code'] === ModuleCatalog::USERS) {
                $meta = $row;
                break;
            }
        }
        if ($meta === null) {
            throw new \InvalidArgumentException('Unknown module: users');
        }

        return new self(
            code: $meta['code'],
            label: $meta['label'],
            sourceSystem: $meta['sourceSystem'],
            sortOrder: $meta['sortOrder'],
            extractor: $extractor,
            normalizer: new CanonicalNormalizer(ModuleCatalog::USERS),
            validators: [
                new RequiredFieldValidator(['legacyId', 'legacyWordPressUserId']),
                new UserEmailValidator($emails ?? new NullExistingNormalizedEmailLookup()),
                new UserStaffValidator(),
                new UserContactValidator(),
                new UserRoleValidator(),
                new UserEntraValidator(),
            ],
            mapper: new StagingMapper(),
            executor: new StagingOnlyExecutor(),
            verifier: new SnapshotVerifier(),
            implemented: true,
        );
    }

    /**
     * Sanitized harness rows only. No passwords, no production identifiers.
     *
     * @return list<array<string, mixed>>
     */
    private static function fixturesFor(string $code): array
    {
        if ($code === ModuleCatalog::USERS) {
            return [
                [
                    'legacyId' => 'fixture-user-1',
                    'email' => 'staff.fixture@example.invalid',
                    'login' => 'fixture-staff',
                    'displayName' => 'Fixture Staff',
                    'kind' => 'staff',
                ],
                [
                    'legacyId' => 'fixture-user-2',
                    'email' => '',
                    'login' => 'fixture-missing-email',
                    'displayName' => 'Fixture Incomplete',
                    'kind' => 'customer',
                ],
                [
                    'legacyId' => 'fixture-user-3',
                    'email' => 'staff.fixture@example.invalid',
                    'login' => 'fixture-duplicate',
                    'displayName' => 'Fixture Duplicate Email',
                    'kind' => 'staff',
                ],
            ];
        }

        return [[
            'legacyId' => 'fixture-' . $code . '-1',
            'label' => 'Fixture ' . $code,
        ]];
    }

    public function code(): string
    {
        return $this->code;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function sourceSystem(): string
    {
        return $this->sourceSystem;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    public function isImplemented(): bool
    {
        return $this->implemented;
    }

    public function extractor(): Extractor
    {
        return $this->extractor;
    }

    public function normalizer(): Normalizer
    {
        return $this->normalizer;
    }

    public function validators(): array
    {
        return $this->validators;
    }

    public function mapper(): Mapper
    {
        return $this->mapper;
    }

    public function executor(): Executor
    {
        return $this->executor;
    }

    public function verifier(): Verifier
    {
        return $this->verifier;
    }
}
