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
use TriCab\Core\Expense\Application\Service\ImportExpenseBankFeedService;

final class ImportExpenseBankFeedAction
{
    public function __construct(
        private readonly ImportExpenseBankFeedService $service,
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
                    (string) ($body['country'] ?? ''),
                    (string) ($body['csvContents'] ?? $body['csv'] ?? ''),
                    isset($body['filename']) ? (string) $body['filename'] : (isset($body['fileName']) ? (string) $body['fileName'] : null),
                ),
            );
        } catch (Throwable $e) {
            return $this->errors->map($e);
        }
    }
}
