<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\ChangeProductStyleParentCommand;
use TriCab\Core\Product\Application\Command\UpsertProductStyleCommand;
use TriCab\Core\Product\Application\Exception\DuplicateProductStyleCode;
use TriCab\Core\Product\Contract\ProductStyleRepository;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\Service\StyleHierarchyCycleGuard;
use TriCab\Core\Product\Domain\ValueObject\ProductStyleCode;
use TriCab\Core\Product\Domain\ValueObject\StyleInheritanceMode;

/**
 * Operator-approved Style Family Tree + Product.Code → ProductStyle apply.
 * Creates missing LOCAL styles, applies approved ParentStyleId links, then exact assignments.
 * Never enables inheritance or migrates specs/media.
 */
final class ApplyApprovedStyleFamilyTreeAndMappingService
{
    public const BATCH_SOURCE = 'style-family-product-mapping-apply-development';

    public function __construct(
        private readonly ManageProductStyleService $manage,
        private readonly AssignProductStyleService $assignments,
        private readonly ProductStyleRepository $styles,
        private readonly ProductAuditRecorder $audit,
        private readonly StyleHierarchyCycleGuard $cycleGuard = new StyleHierarchyCycleGuard(),
    ) {
    }

    /**
     * @param list<string> $createStyleCodes
     * @param list<array{parentStyleCode: string, childStyleCode: string}> $parentLinks
     * @param list<array{productPublicId: string, styleCode: string}> $assignments
     * @param list<array{productPublicId: string, fromStyleCode: string, toStyleCode: string}> $replacements
     * @return array<string, mixed>
     */
    public function apply(
        ActorContext $actor,
        bool $confirmed,
        bool $developmentEnvironment,
        array $createStyleCodes,
        array $parentLinks,
        array $assignments,
        array $replacements,
        array $excludedParentLinks = [['parentStyleCode' => 'PJ', 'childStyleCode' => 'PJT']],
    ): array {
        if (!$confirmed) {
            throw InvalidProductValue::for('confirmed', 'false');
        }
        if (!$developmentEnvironment) {
            throw new \RuntimeException('Style family apply is permitted only in the development environment.');
        }

        $excluded = [];
        foreach ($excludedParentLinks as $edge) {
            $excluded[$this->edgeKey((string) ($edge['parentStyleCode'] ?? ''), (string) ($edge['childStyleCode'] ?? ''))] = true;
        }

        $createResults = $this->createStyles($actor, $createStyleCodes);
        $parentResults = $this->applyParentLinks($actor, $parentLinks, $excluded);
        $assignmentResults = $this->applyExactAssignments($actor, $assignments);
        $replaceResults = $this->replaceAssignments($actor, $replacements);
        $cycles = $this->validateCycles();
        $inheritance = $this->assertCreatedStylesRemainLocal($createResults);

        return [
            'batchSource' => self::BATCH_SOURCE,
            'createdStyles' => $createResults,
            'parentLinks' => $parentResults,
            'assignments' => $assignmentResults,
            'replacedAssignments' => $replaceResults,
            'cycleValidation' => $cycles,
            'inheritanceUnchanged' => $inheritance,
            'excludedParentLinks' => array_values(array_map(
                static fn (string $key): array => [
                    'parentStyleCode' => explode('→', $key)[0],
                    'childStyleCode' => explode('→', $key)[1] ?? '',
                    'applied' => false,
                    'reason' => 'EXCLUDED_BY_OPERATOR',
                ],
                array_keys($excluded),
            )),
            'counters' => [
                'stylesCreated' => count(array_filter($createResults, static fn (array $r): bool => ($r['result'] ?? '') === 'CREATED')),
                'stylesAlreadyExisted' => count(array_filter($createResults, static fn (array $r): bool => ($r['result'] ?? '') === 'ALREADY_EXISTS')),
                'stylesFailed' => count(array_filter($createResults, static fn (array $r): bool => ($r['result'] ?? '') === 'FAILED')),
                'stylesConflicts' => count(array_filter($createResults, static fn (array $r): bool => ($r['result'] ?? '') === 'CONFLICT')),
                'parentLinksCreated' => count(array_filter($parentResults, static fn (array $r): bool => ($r['result'] ?? '') === 'LINKED')),
                'parentLinksAlreadyCorrect' => count(array_filter($parentResults, static fn (array $r): bool => ($r['result'] ?? '') === 'ALREADY_CORRECT')),
                'parentConflicts' => count(array_filter($parentResults, static fn (array $r): bool => ($r['result'] ?? '') === 'CONFLICT')),
                'parentFailed' => count(array_filter($parentResults, static fn (array $r): bool => ($r['result'] ?? '') === 'FAILED')),
                'parentExcluded' => count($excluded),
                'assignmentsCreated' => count(array_filter($assignmentResults, static fn (array $r): bool => !empty($r['applied']))),
                'assignmentsAlreadyCorrect' => count(array_filter($assignmentResults, static fn (array $r): bool => str_starts_with((string) ($r['reason'] ?? ''), 'ALREADY_ASSIGNED_SAME'))),
                'assignmentConflicts' => count(array_filter($assignmentResults, static fn (array $r): bool => str_contains((string) ($r['reason'] ?? ''), 'CONFLICT'))),
                'assignmentsFailed' => count(array_filter($assignmentResults, static fn (array $r): bool => empty($r['applied']) && !str_starts_with((string) ($r['reason'] ?? ''), 'ALREADY_ASSIGNED_SAME'))),
                'assignmentsReplaced' => count(array_filter($replaceResults, static fn (array $r): bool => !empty($r['applied']))),
                'selfCycles' => $cycles['selfCycles'],
                'directCycles' => $cycles['directCycles'],
                'deepCycles' => $cycles['deepCycles'],
            ],
        ];
    }

    /**
     * @param list<string> $styleCodes
     * @return list<array<string, mixed>>
     */
    private function createStyles(ActorContext $actor, array $styleCodes): array
    {
        $results = [];
        foreach ($styleCodes as $raw) {
            $code = strtoupper(trim($raw));
            if ($code === '') {
                $results[] = ['styleCode' => $code, 'result' => 'FAILED', 'reason' => 'styleCode is required.'];
                continue;
            }
            try {
                $code = ProductStyleCode::fromString($code)->toString();
            } catch (\Throwable $exception) {
                $results[] = ['styleCode' => $code, 'result' => 'FAILED', 'reason' => $exception->getMessage()];
                continue;
            }
            $existing = $this->styles->findByStyleCode($code, false);
            if ($existing !== null) {
                $results[] = [
                    'styleCode' => $code,
                    'publicId' => $existing->publicId,
                    'result' => 'ALREADY_EXISTS',
                    'reason' => 'Style already exists. Duplicate not created. No suffixing.',
                    'inheritance' => $existing->inheritance->toMap(),
                    'parentStyleCode' => $existing->parentStyleCode,
                ];
                continue;
            }
            try {
                $style = $this->manage->create($actor, new UpsertProductStyleCommand(
                    styleCode: $code,
                    displayName: $code,
                    parentPublicId: null,
                    isActive: true,
                ));
            } catch (DuplicateProductStyleCode $exception) {
                $again = $this->styles->findByStyleCode($code, false);
                $results[] = [
                    'styleCode' => $code,
                    'publicId' => $again?->publicId,
                    'result' => 'CONFLICT',
                    'reason' => $exception->getMessage(),
                ];
                continue;
            } catch (\Throwable $exception) {
                $results[] = ['styleCode' => $code, 'result' => 'FAILED', 'reason' => $exception->getMessage()];
                continue;
            }

            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'product-style',
                entityId: null,
                actionCode: 'created',
                newValues: [
                    'publicId' => $style->publicId,
                    'styleCode' => $style->styleCode,
                    'displayName' => $style->displayName,
                    'parentStyleId' => null,
                    'inheritance' => $style->inheritance->toMap(),
                    'source' => 'operator-approved-style-family',
                    'batch' => self::BATCH_SOURCE,
                ],
                sourceCode: self::BATCH_SOURCE,
            );

            $results[] = [
                'styleCode' => $code,
                'publicId' => $style->publicId,
                'result' => 'CREATED',
                'reason' => 'LOCAL root ProductStyle created.',
                'inheritance' => $style->inheritance->toMap(),
                'parentStyleCode' => null,
            ];
        }

        return $results;
    }

    /**
     * @param list<array{parentStyleCode: string, childStyleCode: string}> $parentLinks
     * @param array<string, true> $excluded
     * @return list<array<string, mixed>>
     */
    private function applyParentLinks(ActorContext $actor, array $parentLinks, array $excluded): array
    {
        $results = [];
        foreach ($parentLinks as $edge) {
            $parentCode = strtoupper(trim((string) ($edge['parentStyleCode'] ?? '')));
            $childCode = strtoupper(trim((string) ($edge['childStyleCode'] ?? '')));
            $key = $this->edgeKey($parentCode, $childCode);
            if (isset($excluded[$key])) {
                $results[] = [
                    'parentStyleCode' => $parentCode,
                    'childStyleCode' => $childCode,
                    'result' => 'EXCLUDED',
                    'reason' => 'EXCLUDED_BY_OPERATOR',
                ];
                continue;
            }
            if ($parentCode === '' || $childCode === '') {
                $results[] = [
                    'parentStyleCode' => $parentCode,
                    'childStyleCode' => $childCode,
                    'result' => 'FAILED',
                    'reason' => 'parentStyleCode and childStyleCode are required.',
                ];
                continue;
            }

            $parent = $this->styles->findByStyleCode($parentCode, false);
            $child = $this->styles->findByStyleCode($childCode, false);
            if ($parent === null || !$parent->isActive || $parent->isDeleted) {
                $results[] = [
                    'parentStyleCode' => $parentCode,
                    'childStyleCode' => $childCode,
                    'result' => 'FAILED',
                    'reason' => 'Parent ProductStyle missing, inactive, or deleted.',
                ];
                continue;
            }
            if ($child === null || !$child->isActive || $child->isDeleted) {
                $results[] = [
                    'parentStyleCode' => $parentCode,
                    'childStyleCode' => $childCode,
                    'result' => 'FAILED',
                    'reason' => 'Child ProductStyle missing, inactive, or deleted.',
                ];
                continue;
            }

            if ($child->parentStyleCode !== null && strtoupper($child->parentStyleCode) === $parentCode) {
                $results[] = [
                    'parentStyleCode' => $parentCode,
                    'childStyleCode' => $childCode,
                    'result' => 'ALREADY_CORRECT',
                    'reason' => 'ParentStyleId already correct. No rewrite.',
                ];
                continue;
            }
            if ($child->parentStyleCode !== null && strtoupper((string) $child->parentStyleCode) !== $parentCode) {
                $results[] = [
                    'parentStyleCode' => $parentCode,
                    'childStyleCode' => $childCode,
                    'result' => 'CONFLICT',
                    'reason' => 'Child already has ParentStyleId=' . $child->parentStyleCode . '. Not overwritten.',
                    'currentParent' => $child->parentStyleCode,
                ];
                continue;
            }

            try {
                $ancestorIds = array_map(
                    static fn (array $a): string => $a['publicId'],
                    $parent->ancestors,
                );
                $ancestorIds = array_merge([$parent->publicId], $ancestorIds);
                $this->cycleGuard->assertCanSetParent($child->publicId, $parent->publicId, $ancestorIds);
                $updated = $this->manage->changeParent($actor, new ChangeProductStyleParentCommand(
                    stylePublicId: $child->publicId,
                    parentPublicId: $parent->publicId,
                    expectedRowVersion: $child->rowVersion,
                ));
            } catch (\Throwable $exception) {
                $results[] = [
                    'parentStyleCode' => $parentCode,
                    'childStyleCode' => $childCode,
                    'result' => 'FAILED',
                    'reason' => $exception->getMessage(),
                ];
                continue;
            }

            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'product-style',
                entityId: null,
                actionCode: 'parent-changed',
                previousValues: ['parentStyleCode' => null, 'parentPublicId' => null],
                newValues: [
                    'childStyleCode' => $childCode,
                    'parentStyleCode' => $parentCode,
                    'parentPublicId' => $parent->publicId,
                    'source' => 'operator-approved-style-family',
                    'batch' => self::BATCH_SOURCE,
                ],
                sourceCode: self::BATCH_SOURCE,
            );

            $results[] = [
                'parentStyleCode' => $parentCode,
                'childStyleCode' => $childCode,
                'result' => 'LINKED',
                'reason' => 'ParentStyleId applied.',
                'childPublicId' => $updated->publicId,
                'parentPublicId' => $parent->publicId,
                'inheritance' => $updated->inheritance->toMap(),
            ];
        }

        return $results;
    }

    /**
     * @param list<array{productPublicId: string, styleCode: string}> $assignments
     * @return list<array<string, mixed>>
     */
    private function applyExactAssignments(ActorContext $actor, array $assignments): array
    {
        $resolved = [];
        foreach ($assignments as $row) {
            $productPublicId = trim((string) ($row['productPublicId'] ?? ''));
            $styleCode = strtoupper(trim((string) ($row['styleCode'] ?? '')));
            $style = $styleCode !== '' ? $this->styles->findByStyleCode($styleCode, false) : null;
            $resolved[] = [
                'productPublicId' => $productPublicId,
                'productStylePublicId' => $style?->publicId ?? '',
                'styleCode' => $styleCode,
            ];
        }

        $results = $this->assignments->applySelected($actor, $resolved, true);
        foreach ($results as $i => $result) {
            if (!empty($result['applied'])) {
                $this->audit->record(
                    actor: $actor,
                    productPublicId: \TriCab\Core\Product\Domain\ValueObject\ProductPublicId::fromString((string) $result['productPublicId']),
                    entityTypeCode: 'product-style-assignment',
                    entityId: null,
                    actionCode: 'assigned',
                    newValues: [
                        'ProductPublicId' => $result['productPublicId'],
                        'StyleCode' => $resolved[$i]['styleCode'] ?? null,
                        'mappingRule' => 'PRODUCT_CODE_EXACT',
                        'batch' => self::BATCH_SOURCE,
                    ],
                    sourceCode: self::BATCH_SOURCE,
                );
            }
        }

        return $results;
    }

    /**
     * @param list<array{productPublicId: string, fromStyleCode: string, toStyleCode: string}> $replacements
     * @return list<array<string, mixed>>
     */
    private function replaceAssignments(ActorContext $actor, array $replacements): array
    {
        $results = $this->assignments->replaceSelected($actor, $replacements, true);
        foreach ($results as $i => $result) {
            if (!empty($result['applied'])) {
                $row = $replacements[$i] ?? [];
                $this->audit->record(
                    actor: $actor,
                    productPublicId: \TriCab\Core\Product\Domain\ValueObject\ProductPublicId::fromString((string) $result['productPublicId']),
                    entityTypeCode: 'product-style-assignment',
                    entityId: null,
                    actionCode: 'replaced',
                    previousValues: [
                        'fromStyleCode' => $row['fromStyleCode'] ?? null,
                    ],
                    newValues: [
                        'ProductPublicId' => $result['productPublicId'],
                        'StyleCode' => $row['toStyleCode'] ?? null,
                        'mappingRule' => 'PRODUCT_CODE_EXACT',
                        'batch' => self::BATCH_SOURCE,
                    ],
                    sourceCode: self::BATCH_SOURCE,
                );
            }
        }

        return $results;
    }

    /**
     * @return array{selfCycles: int, directCycles: int, deepCycles: int}
     */
    private function validateCycles(): array
    {
        $self = 0;
        $direct = 0;
        $deep = 0;
        $byCode = [];
        foreach ($this->styles->listAll(false) as $style) {
            $byCode[strtoupper($style->styleCode)] = $style;
        }
        foreach ($byCode as $code => $style) {
            $parent = $style->parentStyleCode !== null ? strtoupper($style->parentStyleCode) : null;
            if ($parent === null) {
                continue;
            }
            if ($parent === $code) {
                ++$self;
                continue;
            }
            $parentStyle = $byCode[$parent] ?? null;
            if ($parentStyle !== null && $parentStyle->parentStyleCode !== null
                && strtoupper($parentStyle->parentStyleCode) === $code) {
                ++$direct;
            }
            $seen = [$code => true];
            $cursor = $parent;
            $depth = 0;
            while ($cursor !== null && $depth < 64) {
                if (isset($seen[$cursor])) {
                    ++$deep;
                    break;
                }
                $seen[$cursor] = true;
                $next = $byCode[$cursor] ?? null;
                $cursor = $next?->parentStyleCode !== null ? strtoupper((string) $next->parentStyleCode) : null;
                ++$depth;
            }
        }

        return ['selfCycles' => $self, 'directCycles' => $direct, 'deepCycles' => $deep];
    }

    /**
     * @param list<array<string, mixed>> $createResults
     * @return array{ok: bool, nonLocal: list<string>}
     */
    private function assertCreatedStylesRemainLocal(array $createResults): array
    {
        $nonLocal = [];
        foreach ($createResults as $row) {
            if (($row['result'] ?? '') !== 'CREATED') {
                continue;
            }
            $code = (string) ($row['styleCode'] ?? '');
            $style = $this->styles->findByStyleCode($code, false);
            if ($style === null) {
                $nonLocal[] = $code . ':missing';
                continue;
            }
            foreach ($style->inheritance->toMap() as $type => $mode) {
                if ($mode !== StyleInheritanceMode::LOCAL) {
                    $nonLocal[] = $code . ':' . $type . '=' . $mode;
                }
            }
        }

        return ['ok' => $nonLocal === [], 'nonLocal' => $nonLocal];
    }

    private function edgeKey(string $parent, string $child): string
    {
        return strtoupper(trim($parent)) . '→' . strtoupper(trim($child));
    }
}
