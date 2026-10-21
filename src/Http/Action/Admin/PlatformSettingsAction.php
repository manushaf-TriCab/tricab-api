<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\PlatformSettings\PlatformSettingsService;
use TriCab\Api\PlatformSettings\PlatformSettingsVersionConflict;

final class PlatformSettingsAction
{
    public function __construct(private readonly PlatformSettingsService $service, private readonly JsonResponder $responder) {}

    /** @param array<string,mixed> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response);
        try {
            $path = $request->getUri()->getPath();
            if ($request->getMethod() === 'GET') {
                return $this->responder->success(str_ends_with($path, '/history')
                    ? ['items' => $this->service->history()] : $this->service->snapshot());
            }
            $body = JsonRequestBody::requireObject($request);
            $version = $body['expectedVersion'] ?? null;
            if (!is_int($version) || $version < 0) {
                throw new InvalidArgumentException('expectedVersion must be a nonnegative integer.');
            }
            $actor = AdminActorFactory::fromRequest($request);
            if (str_ends_with($path, '/restore')) {
                $restoreVersion = $body['version'] ?? null;
                if (!is_int($restoreVersion) || $restoreVersion < 1) {
                    throw new InvalidArgumentException('version must be a positive integer.');
                }
                return $this->responder->success($this->service->restore($version, $restoreVersion, $actor->userId));
            }
            if (str_ends_with($path, '/reset')) {
                $section = $body['section'] ?? null;
                if ($section !== null && !is_string($section)) {
                    throw new InvalidArgumentException('section must be a string.');
                }
                return $this->responder->success($this->service->reset($version, $section, $actor->userId));
            }
            $overrides = $body['overrides'] ?? null;
            if (!is_array($overrides) || ($overrides !== [] && array_is_list($overrides))) {
                throw new InvalidArgumentException('overrides must be an object.');
            }
            $normalized = [];
            foreach ($overrides as $key => $value) {
                if (!is_string($key)) {
                    throw new InvalidArgumentException('overrides must be an object.');
                }
                $normalized[$key] = $value;
            }
            return $this->responder->success($this->service->replace($version, $normalized, $actor->userId));
        } catch (PlatformSettingsVersionConflict) {
            return $this->responder->error('SETTINGS_VERSION_CONFLICT', 'Settings changed since you loaded them. Reload and retry.', 409);
        } catch (InvalidArgumentException $exception) {
            return $this->responder->error('INVALID_PLATFORM_SETTINGS', $exception->getMessage(), 422);
        }
    }
}
