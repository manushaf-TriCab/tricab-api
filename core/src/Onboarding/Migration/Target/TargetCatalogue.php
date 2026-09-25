<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Migration\Target;

/**
 * The platform content a migrated record can point at (Phase 7.3): for each
 * training module, policy and form, its **published** live version — never a
 * draft, because an unpublished version cannot be allocated.
 *
 * Read once per preview, read-only. It is part of the fingerprint: a version
 * published or retired after a preview makes that preview stale.
 *
 * @phpstan-type TargetQuestion array{questionId: int, ordinal: int, prompt: string, optionKeys: list<string>, correctOptionKey: string}
 * @phpstan-type TargetModule array{slug: string, versionId: int, versionPublicId: string, versionNumber: int, slideIds: list<string>, passMarkPercent: ?int, questions: list<TargetQuestion>}
 * @phpstan-type TargetPolicy array{slug: string, versionId: int, versionPublicId: string, versionNumber: int, documentReference: ?string, requiresAcknowledgement: bool}
 * @phpstan-type TargetForm array{slug: string, versionId: int, versionPublicId: string, versionNumber: int, accessGroup: string, fields: list<array<string, mixed>>, classifications: array<string, int>}
 */
final readonly class TargetCatalogue
{
    /**
     * @param array<string, TargetModule> $modules by slug
     * @param array<string, TargetPolicy> $policies by slug
     * @param array<string, TargetForm> $forms by slug
     */
    public function __construct(
        public array $modules,
        public array $policies,
        public array $forms,
    ) {
    }

    /** @return TargetModule|null */
    public function module(string $slug): ?array
    {
        return $this->modules[$slug] ?? null;
    }

    /** @return TargetPolicy|null */
    public function policy(string $slug): ?array
    {
        return $this->policies[$slug] ?? null;
    }

    /** @return TargetForm|null */
    public function form(string $slug): ?array
    {
        return $this->forms[$slug] ?? null;
    }

    /** What the plan depends on, for the fingerprint: which version of what. */
    public function fingerprintPart(): string
    {
        $parts = [];
        foreach (['m' => $this->modules, 'p' => $this->policies, 'f' => $this->forms] as $kind => $items) {
            ksort($items);
            foreach ($items as $slug => $item) {
                $parts[] = $kind . ':' . $slug . ':' . $item['versionPublicId'];
            }
        }

        return implode("\n", $parts);
    }
}
