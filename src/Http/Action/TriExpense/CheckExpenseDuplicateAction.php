<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\TriExpense;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ExpenseActorFactory;
use TriCab\Api\Http\Auth\ExpenseErrorMapper;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Expense\Application\Service\CheckExpenseDuplicateService;

final class CheckExpenseDuplicateAction
{
    public function __construct(
        private readonly CheckExpenseDuplicateService $service,
        private readonly JsonResponder $responder,
        private readonly ExpenseErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        try {
            $actor = ExpenseActorFactory::fromRequest($request);
            $body = JsonRequestBody::requireObject($request);
            $matches = $this->service->execute(
                $actor,
                isset($body['vendorName']) ? (string) $body['vendorName'] : '',
                isset($body['receiptDate']) && is_string($body['receiptDate']) ? $body['receiptDate'] : null,
                isset($body['excludePublicId']) && is_string($body['excludePublicId']) ? $body['excludePublicId'] : null,
            );

            return $this->responder->success([
                'warning' => $matches !== [],
                'matches' => $matches,
            ]);
        } catch (Throwable $e) {
            return $this->errors->map($e);
        }
    }
}
