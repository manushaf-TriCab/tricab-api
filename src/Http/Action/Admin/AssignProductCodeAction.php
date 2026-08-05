<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\Product\AssignProductCodeRequest;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Application\Command\AssignProductCodeCommand;
use TriCab\Core\Product\Application\Service\AssignProductCodeService;

final class AssignProductCodeAction
{
    public function __construct(
        private readonly AssignProductCodeService $assignCode,
        private readonly AdminMutationTransaction $transaction,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $input = AssignProductCodeRequest::fromArray(JsonRequestBody::requireObject($request));
            $actor = AdminActorFactory::fromRequest($request);

            $result = $this->transaction->run(fn () => $this->assignCode->execute(
                $actor,
                new AssignProductCodeCommand(
                    $input->productPublicId,
                    $input->ruleSetPublicId,
                    $input->segmentValues,
                ),
            ));

            return $this->responder->success(ProductManagementDtoSerializer::mutationResult($result));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
