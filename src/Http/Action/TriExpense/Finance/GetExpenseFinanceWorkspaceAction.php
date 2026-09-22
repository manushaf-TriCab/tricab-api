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
use TriCab\Core\Expense\Application\Service\GetExpenseFinanceWorkspaceService;

final class GetExpenseFinanceWorkspaceAction
{
    public function __construct(
        private readonly GetExpenseFinanceWorkspaceService $service,
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
            $page = JsonValue::intOrNull($params, 'page') ?? 1;
            $pageSize = JsonValue::intOrNull($params, 'pageSize') ?? 50;

            return $this->responder->success(
                $this->service->execute(
                    ExpenseActorFactory::fromRequest($request),
                    JsonValue::stringOrNull($params, 'country'),
                    JsonValue::stringOrNull($params, 'periodPublicId'),
                    JsonValue::stringOrNull($params, 'dateFrom'),
                    JsonValue::stringOrNull($params, 'dateTo'),
                    JsonValue::stringOrNull($params, 'status'),
                    JsonValue::stringOrNull($params, 'search'),
                    JsonValue::stringOrNull($params, 'currency'),
                    JsonValue::stringOrNull($params, 'customer'),
                    $page,
                    $pageSize,
                ),
            );
        } catch (Throwable $e) {
            return $this->errors->map($e);
        }
    }
}
