<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\Product\PreviewProductCodeRequest;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Application\Command\PreviewProductCodeCommand;
use TriCab\Core\Product\Application\Service\PreviewProductCodeService;

final class PreviewProductCodeAction
{
    public function __construct(
        private readonly PreviewProductCodeService $previewCode,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $input = PreviewProductCodeRequest::fromArray(JsonRequestBody::requireObject($request));
            $result = $this->previewCode->execute(new PreviewProductCodeCommand(
                $input->ruleSetPublicId,
                $input->segmentValues,
            ));

            return $this->responder->success(ProductManagementDtoSerializer::codePreview($result));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
