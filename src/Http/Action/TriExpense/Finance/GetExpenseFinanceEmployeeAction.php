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
use TriCab\Core\Expense\Application\Service\GetExpenseFinanceEmployeeService;

final class GetExpenseFinanceEmployeeAction
{
    public function __construct(
        private readonly GetExpenseFinanceEmployeeService $service,
        private readonly JsonResponder $responder,
        private readonly ExpenseErrorMapper $errors,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response);
        try {
            $params = QueryParams::normalize($request->getQueryParams());
            $userIdArg = (string) ($args['userId'] ?? '');
            $userIdOrEmail = str_contains($userIdArg, '@') || !ctype_digit($userIdArg)
                ? rawurldecode($userIdArg)
                : (int) $userIdArg;

            return $this->responder->success(
                $this->service->execute(
                    ExpenseActorFactory::fromRequest($request),
                    $userIdOrEmail,
                    JsonValue::stringOrNull($params, 'dateFrom'),
                    JsonValue::stringOrNull($params, 'dateTo'),
                ),
            );
        } catch (Throwable $e) {
            return $this->errors->map($e);
        }
    }
}
