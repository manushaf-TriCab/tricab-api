<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\CustomerManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Customer\Application\Service\GetCustomerContactService;

final class GetCustomerContactAction
{
    public function __construct(
        private readonly GetCustomerContactService $getCustomerContact,
        private readonly JsonResponder $responder,
        private readonly CustomerManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $contactPublicId = RouteParams::require($request, 'contactPublicId');
            $details = $this->getCustomerContact->execute($contactPublicId);

            return $this->responder->success(CustomerManagementDtoSerializer::customerContactDetails($details));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
