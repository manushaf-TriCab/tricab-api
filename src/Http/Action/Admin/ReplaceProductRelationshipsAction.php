<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\Product\ReplaceRelationshipsRequest;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Application\Command\ReplaceProductRelationshipsCommand;
use TriCab\Core\Product\Application\Service\ReplaceProductRelationshipsService;

final class ReplaceProductRelationshipsAction
{
    public function __construct(
        private readonly ReplaceProductRelationshipsService $replaceRelationships,
        private readonly AdminMutationTransaction $transaction,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $productPublicId = RouteParams::require($request, 'productPublicId');
            $input = ReplaceRelationshipsRequest::fromArray(JsonRequestBody::requireObject($request));
            $actor = AdminActorFactory::fromRequest($request);

            $items = self::normalizeRelationshipItems($input->items);

            $this->transaction->run(fn () => $this->replaceRelationships->execute(
                $actor,
                new ReplaceProductRelationshipsCommand(
                    $productPublicId,
                    $input->type,
                    $items,
                ),
            ));

            return $this->responder->success(ProductManagementDtoSerializer::acknowledged());
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<array{
     *   relatedProductPublicId: string,
     *   displayOrder?: int,
     *   accessoryRequirementCode?: ?string,
     *   notes?: ?string
     * }>
     */
    private static function normalizeRelationshipItems(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            $relatedProductPublicId = $item['relatedProductPublicId'] ?? null;
            if (!is_string($relatedProductPublicId)) {
                continue;
            }

            $row = [
                'relatedProductPublicId' => $relatedProductPublicId,
            ];
            if (array_key_exists('displayOrder', $item) && is_int($item['displayOrder'])) {
                $row['displayOrder'] = $item['displayOrder'];
            }
            if (array_key_exists('accessoryRequirementCode', $item) && is_string($item['accessoryRequirementCode'])) {
                $row['accessoryRequirementCode'] = $item['accessoryRequirementCode'];
            }
            if (array_key_exists('notes', $item) && is_string($item['notes'])) {
                $row['notes'] = $item['notes'];
            }
            $normalized[] = $row;
        }

        return $normalized;
    }
}
