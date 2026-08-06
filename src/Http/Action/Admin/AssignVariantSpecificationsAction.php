<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\Product\AssignSpecificationsRequest;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Application\Command\AssignVariantSpecificationsCommand;
use TriCab\Core\Product\Application\Service\AssignVariantSpecificationsService;

final class AssignVariantSpecificationsAction
{
    public function __construct(
        private readonly AssignVariantSpecificationsService $assignSpecifications,
        private readonly AdminMutationTransaction $transaction,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $variantPublicId = RouteParams::require($request, 'variantPublicId');
            $input = AssignSpecificationsRequest::fromArray(JsonRequestBody::requireObject($request));
            $actor = AdminActorFactory::fromRequest($request);

            $values = self::normalizeSpecValues($input->values);

            $this->transaction->run(fn () => $this->assignSpecifications->execute(
                $actor,
                new AssignVariantSpecificationsCommand($variantPublicId, $values),
            ));

            return $this->responder->success(ProductManagementDtoSerializer::acknowledged());
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }

    /**
     * @param list<array<string, mixed>> $values
     *
     * @return list<array{
     *   definitionPublicId: string,
     *   valueText?: ?string,
     *   valueLongText?: ?string,
     *   valueInteger?: ?int,
     *   valueDecimal?: ?string,
     *   valueBoolean?: ?bool,
     *   valueDate?: ?string,
     *   valueUrl?: ?string,
     *   valueMinDecimal?: ?string,
     *   valueMaxDecimal?: ?string,
     *   optionPublicId?: ?string,
     *   optionPublicIds?: list<string>
     * }>
     */
    private static function normalizeSpecValues(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $definitionPublicId = $value['definitionPublicId'] ?? null;
            if (!is_string($definitionPublicId)) {
                continue;
            }

            $item = [
                'definitionPublicId' => $definitionPublicId,
            ];
            foreach ([
                'valueText',
                'valueLongText',
                'valueDecimal',
                'valueDate',
                'valueUrl',
                'valueMinDecimal',
                'valueMaxDecimal',
                'optionPublicId',
            ] as $stringField) {
                if (array_key_exists($stringField, $value) && $value[$stringField] !== null) {
                    $item[$stringField] = is_string($value[$stringField]) ? $value[$stringField] : null;
                }
            }
            if (array_key_exists('valueInteger', $value) && $value['valueInteger'] !== null) {
                $item['valueInteger'] = is_int($value['valueInteger']) ? $value['valueInteger'] : null;
            }
            if (array_key_exists('valueBoolean', $value) && $value['valueBoolean'] !== null) {
                $item['valueBoolean'] = is_bool($value['valueBoolean']) ? $value['valueBoolean'] : null;
            }
            if (isset($value['optionPublicIds']) && is_array($value['optionPublicIds'])) {
                $optionPublicIds = [];
                foreach ($value['optionPublicIds'] as $option) {
                    if (is_string($option)) {
                        $optionPublicIds[] = $option;
                    }
                }
                $item['optionPublicIds'] = $optionPublicIds;
            }
            $normalized[] = $item;
        }

        return $normalized;
    }
}
