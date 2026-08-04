<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\UserManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Identity\Application\Service\UserManagement\GetUserDetailsService;

final class GetUserAction
{
    public function __construct(
        private readonly GetUserDetailsService $getUserDetails,
        private readonly JsonResponder $responder,
        private readonly UserManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $publicId = RouteParams::require($request, 'publicId');
            $details = $this->getUserDetails->execute($publicId);

            return $this->responder->success(UserManagementDtoSerializer::userDetails($details));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
