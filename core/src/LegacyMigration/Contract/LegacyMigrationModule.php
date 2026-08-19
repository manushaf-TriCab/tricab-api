<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Contract;

interface LegacyMigrationModule
{
    public function code(): string;

    public function label(): string;

    public function sourceSystem(): string;

    public function sortOrder(): int;

    /** True only when a real (non-fixture) extractor/executor is registered. */
    public function isImplemented(): bool;

    public function extractor(): Extractor;

    public function normalizer(): Normalizer;

    /** @return list<Validator> */
    public function validators(): array;

    public function mapper(): Mapper;

    public function executor(): Executor;

    public function verifier(): Verifier;
}
