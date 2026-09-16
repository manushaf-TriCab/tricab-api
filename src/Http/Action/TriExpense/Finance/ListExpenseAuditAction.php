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
use TriCab\Core\Expense\Application\Service\ListExpenseAuditService;

final class ListExpenseAuditAction
{
    public function __construct(
        private readonly ListExpenseAuditService $service,
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
            $limit = JsonValue::intOrNull($params, 'limit');

            return $this->responder->success([
                'items' => $this->service->execute(
                    ExpenseActorFactory::fromRequest($request),
                    $limit !== null && $limit > 0 ? $limit : null,
                ),
            ]);
        } catch (Throwable $e) {
            return $this->errors->map($e);
        }
    }
}
