<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Exception\HttpRequestValidationFailed;
use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Groups\EntraGroupDirectoryUnavailable;
use TriCab\Api\Identity\Groups\GraphNotConfigured;
use TriCab\Api\Identity\Groups\GraphPermissionNotGranted;
use TriCab\Api\Identity\Groups\GroupException;
use TriCab\Api\Identity\Groups\GroupService;

/**
 * Platform groups (`/api/v1/admin/groups`). Every route holds `IDENTITY.GROUPS.MANAGE` (Administrators).
 * Members of an Entra-linked group are read-only here; they follow Entra. When Graph cannot be read
 * (consent not granted) the Entra routes answer 503 with a typed code, and the rest keep working.
 */
final class GroupsController
{
    public const PERMISSION = 'IDENTITY.GROUPS.MANAGE';

    public function __construct(
        private readonly GroupService $groups,
        private readonly JsonResponder $responder,
    ) {
    }

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        return $this->run(function () use ($request): ResponseInterface {
            $query = QueryParams::normalize($request->getQueryParams());
            $page = max(1, QueryParams::int($query, 'page', 1));
            $pageSize = min(100, max(1, QueryParams::int($query, 'pageSize', 25)));

            return $this->responder->success($this->groups->list(
                QueryParams::stringOrNull($query, 'q'),
                QueryParams::stringOrNull($query, 'source'),
                QueryParams::boolOrNull($query, 'includeUnlinked') ?? true,
                $page,
                $pageSize,
            ));
        });
    }

    public function lookup(ServerRequestInterface $request): ResponseInterface
    {
        return $this->run(function () use ($request): ResponseInterface {
            $query = QueryParams::normalize($request->getQueryParams());

            return $this->responder->success(['items' => $this->groups->lookup(QueryParams::string($query, 'q', ''))]);
        });
    }

    public function searchEntra(ServerRequestInterface $request): ResponseInterface
    {
        return $this->run(function () use ($request): ResponseInterface {
            $query = QueryParams::normalize($request->getQueryParams());

            return $this->responder->success(['items' => $this->groups->searchEntra(QueryParams::string($query, 'q', ''))]);
        });
    }

    /** @param array<string, string> $args */
    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->run(fn (): ResponseInterface => $this->responder->success($this->groups->get($args['publicId'] ?? '')));
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        return $this->run(function () use ($request): ResponseInterface {
            $body = JsonRequestBody::requireObject($request);
            $created = $this->groups->create(
                $this->string($body, 'name'),
                $this->nullableString($body, 'description'),
                $this->actorId($request),
            );

            return $this->responder->success($created, 201);
        });
    }

    /** @param array<string, string> $args */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->run(function () use ($request, $args): ResponseInterface {
            $body = JsonRequestBody::requireObject($request);

            return $this->responder->success($this->groups->update(
                $args['publicId'] ?? '',
                $this->string($body, 'name'),
                $this->nullableString($body, 'description'),
            ));
        });
    }

    /** @param array<string, string> $args */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->run(function () use ($args): ResponseInterface {
            $this->groups->delete($args['publicId'] ?? '');

            return $this->responder->success(['deleted' => true]);
        });
    }

    /** @param array<string, string> $args */
    public function addMember(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->run(function () use ($request, $args): ResponseInterface {
            $body = JsonRequestBody::requireObject($request);

            return $this->responder->success($this->groups->addMember(
                $args['publicId'] ?? '',
                $this->string($body, 'userPublicId'),
                $this->actorId($request),
            ));
        });
    }

    /** @param array<string, string> $args */
    public function removeMember(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->run(fn (): ResponseInterface => $this->responder->success(
            $this->groups->removeMember($args['publicId'] ?? '', $args['userPublicId'] ?? ''),
        ));
    }

    public function link(ServerRequestInterface $request): ResponseInterface
    {
        return $this->run(function () use ($request): ResponseInterface {
            $body = JsonRequestBody::requireObject($request);

            return $this->responder->success($this->groups->link($this->string($body, 'entraGroupId'), $this->actorId($request)), 201);
        });
    }

    /** @param array<string, string> $args */
    public function unlink(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->run(fn (): ResponseInterface => $this->responder->success($this->groups->unlink($args['publicId'] ?? '')));
    }

    /** @param array<string, string> $args */
    public function sync(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->run(function () use ($request, $args): ResponseInterface {
            // Optional body {"allowShrink": true}: an Administrator accepts a real shrink past the short-read guard.
            $raw = trim((string) $request->getBody());
            $decoded = $raw === '' ? [] : json_decode($raw, true);
            $allow = is_array($decoded) && ($decoded['allowShrink'] ?? false) === true;

            return $this->responder->success($this->groups->syncNow($args['publicId'] ?? '', $allow));
        });
    }

    /** @param callable(): ResponseInterface $work */
    private function run(callable $work): ResponseInterface
    {
        try {
            return $work();
        } catch (GroupException $e) {
            return $this->responder->error($e->errorCode(), $e->getMessage(), $e->status);
        } catch (HttpRequestValidationFailed $e) {
            return $this->responder->error($e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (EntraGroupDirectoryUnavailable $e) {
            $code = match (true) {
                $e instanceof GraphPermissionNotGranted => 'GRAPH_PERMISSION_NOT_GRANTED',
                $e instanceof GraphNotConfigured => 'GRAPH_NOT_CONFIGURED',
                default => 'GRAPH_UNAVAILABLE',
            };

            return $this->responder->error($code, $e->getMessage(), 503);
        } catch (Throwable $e) {
            error_log('Groups request failed: ' . get_class($e) . ': ' . $e->getMessage());

            return $this->responder->error('GROUPS_REQUEST_FAILED', 'The groups request failed.', 500);
        }
    }

    private function actorId(ServerRequestInterface $request): int
    {
        return AdminActorFactory::fromRequest($request)->userId;
    }

    /** @param array<string, mixed> $body */
    private function string(array $body, string $key): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value)) {
            throw GroupException::invalid($key . ' is required.');
        }

        return $value;
    }

    /** @param array<string, mixed> $body */
    private function nullableString(array $body, string $key): ?string
    {
        $value = $body[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw GroupException::invalid($key . ' must be text.');
        }

        return $value;
    }
}
