<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\PublicContent;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Content\PublicContentRepository;
use TriCab\Api\Http\Response\JsonResponder;

final class GetPublicAboutAction
{
    public function __construct(
        private readonly PublicContentRepository $content,
        private readonly JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($request, $response);
        try {
            return $this->responder->success(
                $this->content->about(),
                200,
                ['Cache-Control' => 'public, max-age=300'],
            );
        } catch (Throwable $exception) {
            return $this->responder->error(
                'PUBLIC_CONTENT_UNAVAILABLE',
                'Unable to load About Us content.',
                500,
            );
        }
    }
}
