<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\TriExpense\Finance;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ExpenseActorFactory;
use TriCab\Api\Http\Auth\ExpenseErrorMapper;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Expense\Application\Service\ListExpenseAuditService;

final class ListExpenseAuditAction
{
    public function __construct(
        private readonly ListExpenseAuditService $service,
        private readonly JsonResponder $responder,
        private readonly ExpenseErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response, $args);
        try {
            $params = $request->getQueryParams();
            $limit = isset($params['limit']) ? (int) $params['limit'] : null;

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
