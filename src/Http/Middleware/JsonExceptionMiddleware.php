<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpException;
use Slim\Exception\HttpNotFoundException;
use TriCab\Api\Config\Settings;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Infrastructure\Database\DatabaseAuthFailed;
use TriCab\Api\Infrastructure\Database\DatabaseUnavailable;
use Throwable;

final class JsonExceptionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly Settings $settings,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (HttpNotFoundException) {
            return $this->responder->error('NOT_FOUND', 'The requested resource was not found.', 404);
        } catch (HttpException $exception) {
            return $this->responder->error(
                'HTTP_ERROR',
                $exception->getMessage() !== '' ? $exception->getMessage() : 'HTTP error.',
                $exception->getCode() >= 400 ? $exception->getCode() : 400,
            );
        } catch (DatabaseAuthFailed $exception) {
            error_log('Database authentication failed: ' . $exception->getMessage());

            return $this->responder->error(
                'DATABASE_AUTH_FAILED',
                'Unable to authenticate with Azure SQL.',
                503,
            );
        } catch (DatabaseUnavailable $exception) {
            error_log('Database unavailable: ' . $exception->getMessage());

            return $this->responder->error(
                'DATABASE_UNAVAILABLE',
                'The database is currently unavailable.',
                503,
            );
        } catch (Throwable $exception) {
            error_log('Unhandled API exception: ' . $exception->getMessage());
            $message = $this->settings->appDebug
                ? $exception->getMessage()
                : 'An unexpected error occurred.';

            return $this->responder->error('INTERNAL_ERROR', $message, 500);
        }
    }
}
