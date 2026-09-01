<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\CableCalculator;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\CableCalculator\Application\Exception\CableCalculationValidationException;
use TriCab\Core\CableCalculator\Application\Service\CalculateCableService;
use TriCab\Core\CableCalculator\Application\Service\ResolveCableCalculationProductsService;

final class PostCableCalculatorCalculateAction
{
    public function __construct(
        private readonly CalculateCableService $calculator,
        private readonly ResolveCableCalculationProductsService $productResolver,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $body = $request->getParsedBody();
            if (!is_array($body)) {
                return $this->responder->error('INVALID_JSON', 'Invalid JSON body.', 400);
            }

            $isPreview = (bool) ($body['isPreview'] ?? false);
            $calcRequest = CableCalculatorRequestMapper::fromHttpBody($body);
            $result = $this->calculator->calculate($calcRequest, $isPreview);

            // Product resolution is post-calculation and must never fail the engineering response.
            try {
                $productResolution = $this->productResolver->resolve($result);
            } catch (Throwable) {
                $productResolution = [];
            }

            return $this->responder->success(CableCalculatorDtoSerializer::result($result, $productResolution));
        } catch (CableCalculationValidationException $exception) {
            $fields = [];
            foreach ($exception->fieldErrors as $field => $message) {
                $fields[$field] = [$message];
            }

            return $this->responder->payload([
                'success' => false,
                'error' => [
                    'code' => 'CABLE_CALCULATION_VALIDATION',
                    'message' => $exception->getMessage(),
                    'fields' => $fields,
                ],
            ], 422);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
