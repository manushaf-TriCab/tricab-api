<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Site\Application\Service\CustomerViewThemeService;

final class GetCustomerViewThemeAction
{
    public function __construct(
        private readonly CustomerViewThemeService $theme,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($request, $response);

        try {
            return $this->responder->success([
                'theme' => $this->theme->get(),
            ]);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
