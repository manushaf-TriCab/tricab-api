<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\TriExpense\Finance;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ExpenseActorFactory;
use TriCab\Api\Http\Auth\ExpenseErrorMapper;
use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Support\JsonValue;
use TriCab\Core\Expense\Application\Service\GetExpenseReconciliationService;

final class GetExpenseReconciliationAction
{
    public function __construct(
        private readonly GetExpenseReconciliationService $service,
        private readonly JsonResponder $responder,
        private readonly ExpenseErrorMapper $errors,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response, $args);
        try {
            $params = QueryParams::normalize($request->getQueryParams());
            $country = JsonValue::stringOrNull($params, 'country');
            $periodPublicId = JsonValue::stringOrNull($params, 'periodPublicId');

            return $this->responder->success(
                $this->service->execute(
                    ExpenseActorFactory::fromRequest($request),
                    $country !== null && trim($country) !== '' ? $country : null,
                    $periodPublicId !== null && trim($periodPublicId) !== '' ? $periodPublicId : null,
                ),
            );
        } catch (Throwable $e) {
            return $this->errors->map($e);
        }
    }
}
