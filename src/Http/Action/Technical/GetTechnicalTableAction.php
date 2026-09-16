<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Technical;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Technical\TechnicalContentRepository;

final class GetTechnicalTableAction
{
    public function __construct(
        private readonly TechnicalContentRepository $content,
        private readonly JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        $keyAttribute = $request->getAttribute('key', '');
        $key = is_string($keyAttribute) ? $keyAttribute : '';
        try {
            return $this->responder->success(
                $this->content->table($key),
                200,
                ['Cache-Control' => 'private, max-age=60'],
            );
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
            if (str_contains($message, 'Invalid Technical content key') || str_contains($message, 'missing')) {
                return $this->responder->error('TECHNICAL_TABLE_NOT_FOUND', 'Technical lookup table not found.', 404);
            }

            return $this->responder->error('TECHNICAL_CONTENT_UNAVAILABLE', 'Unable to load Technical lookup table.', 500);
        } catch (Throwable $exception) {
            return $this->responder->error('TECHNICAL_CONTENT_UNAVAILABLE', 'Unable to load Technical lookup table.', 500);
        }
    }
}
