<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Action\RegionDtoSerializer;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Api\Http\Request\Admin\RegionRequestParser;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Product\Application\Command\RegionStatusCommand;
use TriCab\Core\Product\Application\Service\GetRegionsService;
use TriCab\Core\Product\Application\Service\ManageRegionService;

final class RegionAdminAction
{
    public function __construct(
        private readonly GetRegionsService $get,
        private readonly ManageRegionService $manage,
        private readonly JsonResponder $responder,
        private readonly ProductManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        try {
            $method = strtoupper($request->getMethod());
            $path = rtrim($request->getUri()->getPath(), '/');
            $id = $request->getAttribute('publicId');

            if ($method === 'GET' && !is_string($id)) {
                $result = $this->get->adminList(RegionRequestParser::criteria(
                    QueryParams::normalize($request->getQueryParams()),
                ));
                return $this->responder->success([
                    'items' => array_map(RegionDtoSerializer::record(...), $result->items),
                    'pagination' => ProductManagementDtoSerializer::pagination($result->pagination),
                ]);
            }
            if ($method === 'GET') {
                return $this->responder->success(
                    RegionDtoSerializer::record($this->get->get(RouteParams::require($request, 'publicId'))),
                );
            }

            $actor = AdminActorFactory::fromRequest($request);
            $body = JsonRequestBody::requireObject($request);
            if ($method === 'POST' && !is_string($id)) {
                $record = $this->manage->create($actor, RegionRequestParser::upsert($body));
                return $this->responder->success(RegionDtoSerializer::record($record), 201);
            }
            $publicId = RouteParams::require($request, 'publicId');
            if ($method === 'PUT') {
                $record = $this->manage->update($actor, RegionRequestParser::upsert($body, $publicId));
            } else {
                $command = new RegionStatusCommand(
                    $publicId,
                    RegionRequestParser::expectedRowVersion($body),
                );
                $record = match (true) {
                    $method === 'DELETE' => $this->manage->delete($actor, $command),
                    str_ends_with($path, '/activate') => $this->manage->activate($actor, $command),
                    str_ends_with($path, '/deactivate') => $this->manage->deactivate($actor, $command),
                    default => throw new \InvalidArgumentException('Unsupported region operation.'),
                };
            }
            return $this->responder->success(RegionDtoSerializer::record($record));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
