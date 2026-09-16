<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\TriExpense;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Config\Settings;
use TriCab\Api\Http\Auth\ExpenseActorFactory;
use TriCab\Api\Http\Auth\ExpenseErrorMapper;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Expense\Application\Service\GetExpenseAttachmentFileService;

final class GetExpenseAttachmentFileAction
{
    public function __construct(
        private readonly GetExpenseAttachmentFileService $service,
        private readonly Settings $settings,
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
            $ticket = $this->service->execute(
                ExpenseActorFactory::fromRequest($request),
                $args['id'] ?? '',
                $this->settings->blobDownloadSasTtlSeconds,
            );

            return $this->responder->success($ticket);
        } catch (Throwable $e) {
            return $this->errors->map($e);
        }
    }
}
