<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\TelDirectory;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\TelDirectory\Application\Exception\TelDirectoryUnavailable;
use TriCab\Core\TelDirectory\Application\Service\ListTelDirectoryService;

final class ListTelDirectoryAction
{
    public function __construct(
        private readonly ListTelDirectoryService $listTelDirectory,
        private readonly JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($request, $response);

        try {
            $result = $this->listTelDirectory->execute();

            return $this->responder->success($result);
        } catch (TelDirectoryUnavailable $exception) {
            error_log('[tel-directory] ' . $exception->getMessage());

            return $this->responder->error(
                'TEL_DIRECTORY_UNAVAILABLE',
                'Unable to load the telephone directory right now.',
                503,
            );
        } catch (Throwable $exception) {
            error_log('[tel-directory] unexpected: ' . $exception->getMessage());

            return $this->responder->error(
                'TEL_DIRECTORY_UNAVAILABLE',
                'Unable to load the telephone directory right now.',
                503,
            );
        }
    }
}
