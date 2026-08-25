<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\UpsertProductStyleCommand;
use TriCab\Core\Product\Application\Exception\DuplicateProductStyleCode;
use TriCab\Core\Product\Contract\ProductStyleRepository;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\ValueObject\ProductStyleCode;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

/**
 * Explicit-list apply for Product.Code → ProductStyle mapping.
 * Never means "apply everything". Do not invoke against live Azure in the dry-run phase.
 */
final class ApplyProductCodeStyleMappingService
{
    public function __construct(
        private readonly ManageProductStyleService $manage,
        private readonly AssignProductStyleService $assignments,
        private readonly ProductStyleRepository $styles,
        private readonly ApplicationTransaction $transaction,
    ) {
    }

    /**
     * @param list<array{styleCode?: string, displayName?: string}> $createStyles
     * @param list<array{productPublicId?: string, styleCode?: string, productStylePublicId?: string}> $assignments
     * @param list<array{productPublicId?: string, fromStyleCode?: string, toStyleCode?: string}> $replaceAssignments
     * @return array<string, mixed>
     */
    public function apply(
        ActorContext $actor,
        bool $confirmed,
        array $createStyles,
        array $assignments,
        array $replaceAssignments,
    ): array {
        if (!$confirmed) {
            throw InvalidProductValue::for('confirmed', 'false');
        }

        return $this->transaction->run(function () use ($actor, $createStyles, $assignments, $replaceAssignments): array {
            $created = [];
            foreach ($createStyles as $row) {
                $code = strtoupper(trim((string) ($row['styleCode'] ?? '')));
                if ($code === '') {
                    $created[] = ['styleCode' => $code, 'applied' => false, 'reason' => 'styleCode is required.'];
                    continue;
                }
                try {
                    $code = ProductStyleCode::fromString($code)->toString();
                } catch (\Throwable $exception) {
                    $created[] = ['styleCode' => $code, 'applied' => false, 'reason' => $exception->getMessage()];
                    continue;
                }
                $existing = $this->styles->findByStyleCode($code, false);
                if ($existing !== null) {
                    $created[] = ['styleCode' => $code, 'applied' => false, 'reason' => 'Style already exists. Duplicate not created.'];
                    continue;
                }
                try {
                    $style = $this->manage->create($actor, new UpsertProductStyleCommand(
                        styleCode: $code,
                        displayName: trim((string) ($row['displayName'] ?? '')) !== '' ? (string) $row['displayName'] : $code,
                    ));
                } catch (DuplicateProductStyleCode $exception) {
                    $created[] = ['styleCode' => $code, 'applied' => false, 'reason' => $exception->getMessage()];
                    continue;
                }
                $created[] = [
                    'styleCode' => $code,
                    'applied' => true,
                    'parentStyleId' => $style->parentPublicId,
                    'inheritance' => $style->inheritance->toMap(),
                    'reason' => 'ROOT + LOCAL ProductStyle created.',
                ];
            }

            $resolvedAssignments = [];
            foreach ($assignments as $row) {
                $productPublicId = trim((string) ($row['productPublicId'] ?? ''));
                $stylePublicId = trim((string) ($row['productStylePublicId'] ?? ''));
                $styleCode = strtoupper(trim((string) ($row['styleCode'] ?? '')));
                if ($stylePublicId === '' && $styleCode !== '') {
                    $style = $this->styles->findByStyleCode($styleCode, false);
                    $stylePublicId = $style?->publicId ?? '';
                }
                $resolvedAssignments[] = [
                    'productPublicId' => $productPublicId,
                    'productStylePublicId' => $stylePublicId,
                ];
            }
            $assigned = $this->assignments->applySelected($actor, $resolvedAssignments, true);

            $replaced = $this->assignments->replaceSelected($actor, $replaceAssignments, true);

            return [
                'createdStyles' => $created,
                'assignments' => $assigned,
                'replacedAssignments' => $replaced,
            ];
        });
    }
}
