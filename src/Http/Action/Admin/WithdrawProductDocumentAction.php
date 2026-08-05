<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Product\Http\ProductSubResourceResolver;
use TriCab\Core\Product\Application\Command\WithdrawProductDocumentCommand;
use TriCab\Core\Product\Application\Service\WithdrawProductDocumentService;

final class WithdrawProductDocumentAction
{
    public function __construct(
        private readonly WithdrawProductDocumentService $withdrawDocument,
        private readonly ProductSubResourceResolver $resolver,
        private readonly AdminMutationTransaction $transaction,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $documentPublicId = RouteParams::require($request, 'documentPublicId');
            $productPublicId = $this->resolver->requireProductPublicIdForDocument($documentPublicId);
            $actor = AdminActorFactory::fromRequest($request);

            $this->transaction->run(fn () => $this->withdrawDocument->execute(
                $actor,
                new WithdrawProductDocumentCommand($productPublicId, $documentPublicId),
            ));

            return $this->responder->success(ProductManagementDtoSerializer::acknowledged());
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
