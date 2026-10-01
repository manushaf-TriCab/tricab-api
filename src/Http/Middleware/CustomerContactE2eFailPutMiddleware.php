<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TriCab\Api\CustomerContactReport\Storage\DevelopmentFileCustomerContactReportFileStore;

/**
 * Lets the browser harness fail one evidence upload. Registered only in E2E mode.
 */
final class CustomerContactE2eFailPutMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (strtolower(trim($request->getHeaderLine('X-Customer-Contact-E2e-Fail-Put'))) === '1') {
            DevelopmentFileCustomerContactReportFileStore::failNextPut();
        }

        return $handler->handle($request);
    }
}
