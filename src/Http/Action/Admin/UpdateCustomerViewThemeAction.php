<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Site\Application\Service\CustomerViewThemeService;

final class UpdateCustomerViewThemeAction
{
    public function __construct(
        private readonly CustomerViewThemeService $theme,
        private readonly AdminMutationTransaction $transaction,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $body = JsonRequestBody::requireObject($request);
            $themeInput = $body['theme'] ?? $body;
            if (!is_array($themeInput)) {
                $themeInput = [];
            }
            /** @var array<string, mixed> $themeInput */
            $actor = AdminActorFactory::fromRequest($request);
            $saved = $this->transaction->run(
                fn () => $this->theme->update($actor, $themeInput),
            );

            return $this->responder->success([
                'theme' => $saved,
            ]);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
