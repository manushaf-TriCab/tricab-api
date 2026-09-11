<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\TriExpense\Finance;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ExpenseActorFactory;
use TriCab\Api\Http\Auth\ExpenseErrorMapper;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Expense\Application\Service\OffsetExpenseBankFeedService;

final class OffsetExpenseBankFeedAction
{
    public function __construct(
        private readonly OffsetExpenseBankFeedService $service,
        private readonly JsonResponder $responder,
        private readonly ExpenseErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response, $args);
        try {
            $body = JsonRequestBody::requireObject($request);

            return $this->responder->success(
                $this->service->execute(
                    ExpenseActorFactory::fromRequest($request),
                    (string) ($body['publicIdA'] ?? ''),
                    (string) ($body['publicIdB'] ?? ''),
                    (string) ($body['expectedRowVersionA'] ?? ''),
                    (string) ($body['expectedRowVersionB'] ?? ''),
                ),
            );
        } catch (Throwable $e) {
            return $this->errors->map($e);
        }
    }
}
