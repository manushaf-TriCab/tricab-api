<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Contract\CatalogueNavigationRepository;
use TriCab\Core\Product\Contract\ProductStyleRepository;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;

/**
 * Operator-approved CatalogueNode → ProductStyle link apply (development only).
 * Uses exact PublicIds from the dry-run artifact — no name matching.
 */
final class ApplyApprovedCatalogueNodeProductStylesService
{
    public const BATCH_SOURCE = 'catalogue-product-style-apply-development';

    public function __construct(
        private readonly CatalogueNavigationRepository $catalogue,
        private readonly ProductStyleRepository $styles,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    /**
     * @param list<array{
     *   catalogueNodePublicId: string,
     *   productStylePublicId: string,
     *   styleCode: string,
     *   cataloguePath: string,
     *   nodeName: string
     * }> $approvedLinks
     * @return array<string, mixed>
     */
    public function apply(
        ActorContext $actor,
        bool $confirmed,
        bool $developmentEnvironment,
        array $approvedLinks,
    ): array {
        if (!$confirmed) {
            throw InvalidProductValue::for('confirmed', 'false');
        }
        if (!$developmentEnvironment) {
            throw new \RuntimeException('Catalogue style apply is permitted only in the development environment.');
        }

        $results = [];
        foreach ($approvedLinks as $link) {
            $results[] = $this->applyOne($actor, $link);
        }

        $reconciliation = $this->reconcile($approvedLinks);

        return [
            'batchSource' => self::BATCH_SOURCE,
            'links' => $results,
            'reconciliation' => $reconciliation,
            'counters' => [
                'requested' => count($approvedLinks),
                'created' => count(array_filter($results, static fn (array $r): bool => ($r['result'] ?? '') === 'CREATED')),
                'alreadyExisted' => count(array_filter($results, static fn (array $r): bool => ($r['result'] ?? '') === 'ALREADY_EXISTS')),
                'failed' => count(array_filter($results, static fn (array $r): bool => ($r['result'] ?? '') === 'FAILED')),
                'conflicts' => count(array_filter($results, static fn (array $r): bool => ($r['result'] ?? '') === 'CONFLICT')),
                'missingAfterApply' => $reconciliation['missingAfterApply'],
            ],
        ];
    }

    /**
     * @param array<string, string> $link
     * @return array<string, mixed>
     */
    private function applyOne(ActorContext $actor, array $link): array
    {
        $nodePublicId = strtoupper(trim((string) ($link['catalogueNodePublicId'] ?? '')));
        $stylePublicId = strtoupper(trim((string) ($link['productStylePublicId'] ?? '')));
        $styleCode = strtoupper(trim((string) ($link['styleCode'] ?? '')));
        $cataloguePath = (string) ($link['cataloguePath'] ?? '');
        $nodeName = (string) ($link['nodeName'] ?? '');

        $base = [
            'catalogueNodePublicId' => $nodePublicId,
            'productStylePublicId' => $stylePublicId,
            'styleCode' => $styleCode,
            'cataloguePath' => $cataloguePath,
            'nodeName' => $nodeName,
        ];

        if ($nodePublicId === '' || $stylePublicId === '') {
            return $base + ['result' => 'FAILED', 'reason' => 'Missing PublicId.'];
        }

        $node = $this->catalogue->findNodeByPublicId($nodePublicId, false);
        if ($node === null || $node->isDeleted || !$node->isActive) {
            return $base + ['result' => 'FAILED', 'reason' => 'Catalogue node missing, deleted, or inactive.'];
        }

        $style = $this->styles->findByPublicId($stylePublicId);
        if ($style === null || $style->isDeleted || !$style->isActive) {
            return $base + ['result' => 'FAILED', 'reason' => 'ProductStyle missing, deleted, or inactive.'];
        }
        if ($styleCode !== '' && strtoupper($style->styleCode) !== $styleCode) {
            return $base + [
                'result' => 'CONFLICT',
                'reason' => sprintf('Style PublicId resolves to %s, not approved %s.', $style->styleCode, $styleCode),
            ];
        }

        $existing = $this->catalogue->listNodeProductStyles($nodePublicId, true);
        foreach ($existing as $row) {
            if (strtoupper($row->productStylePublicId) === $stylePublicId) {
                $this->audit->record(
                    actor: $actor,
                    productPublicId: null,
                    entityTypeCode: 'catalogue-node-product-style',
                    entityId: null,
                    actionCode: 'already-exists',
                    newValues: [
                        'batchSource' => self::BATCH_SOURCE,
                        'catalogueNodePublicId' => $nodePublicId,
                        'cataloguePath' => $cataloguePath,
                        'nodeName' => $nodeName,
                        'productStylePublicId' => $stylePublicId,
                        'styleCode' => $style->styleCode,
                    ],
                );

                return $base + ['result' => 'ALREADY_EXISTS', 'reason' => 'Active link already present.'];
            }
        }

        try {
            $this->catalogue->addNodeProductStyle(
                $nodePublicId,
                $stylePublicId,
                10,
                $this->audit->actorUserId($actor),
            );
        } catch (\Throwable $e) {
            return $base + ['result' => 'FAILED', 'reason' => $e->getMessage()];
        }

        $this->audit->record(
            actor: $actor,
            productPublicId: null,
            entityTypeCode: 'catalogue-node-product-style',
            entityId: null,
            actionCode: 'created',
            newValues: [
                'batchSource' => self::BATCH_SOURCE,
                'catalogueNodePublicId' => $nodePublicId,
                'cataloguePath' => $cataloguePath,
                'nodeName' => $nodeName,
                'productStylePublicId' => $stylePublicId,
                'styleCode' => $style->styleCode,
            ],
        );

        return $base + ['result' => 'CREATED', 'reason' => null];
    }

    /**
     * @param list<array<string, string>> $approvedLinks
     * @return array<string, mixed>
     */
    private function reconcile(array $approvedLinks): array
    {
        $missing = [];
        foreach ($approvedLinks as $link) {
            $nodePublicId = strtoupper(trim((string) ($link['catalogueNodePublicId'] ?? '')));
            $stylePublicId = strtoupper(trim((string) ($link['productStylePublicId'] ?? '')));
            $found = false;
            foreach ($this->catalogue->listNodeProductStyles($nodePublicId, true) as $row) {
                if (strtoupper($row->productStylePublicId) === $stylePublicId) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $missing[] = [
                    'catalogueNodePublicId' => $nodePublicId,
                    'productStylePublicId' => $stylePublicId,
                    'styleCode' => $link['styleCode'] ?? '',
                ];
            }
        }

        return [
            'approvedLinks' => count($approvedLinks),
            'verifiedPresent' => count($approvedLinks) - count($missing),
            'missingAfterApply' => count($missing),
            'missing' => $missing,
        ];
    }
}
