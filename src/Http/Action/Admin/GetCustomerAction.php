<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\CustomerManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Customer\Application\Service\GetCustomerService;

final class GetCustomerAction
{
    public function __construct(
        private readonly GetCustomerService $getCustomer,
        private readonly JsonResponder $responder,
        private readonly CustomerManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $publicId = RouteParams::require($request, 'customerPublicId');
            $details = $this->getCustomer->execute($publicId);

            return $this->responder->success(CustomerManagementDtoSerializer::customerDetails($details));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
