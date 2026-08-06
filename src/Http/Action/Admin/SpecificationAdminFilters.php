<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ServerRequestInterface;

final class SpecificationAdminFilters
{
    /** @return array<string, mixed> */
    public static function fromRequest(ServerRequestInterface $request): array
    {
        $query = $request->getQueryParams();
        $filters = [
            'search' => isset($query['search']) && is_string($query['search']) ? $query['search'] : null,
            'includeDeleted' => (($query['includeDeleted'] ?? '') === 'true' || ($query['includeDeleted'] ?? '') === '1'),
        ];
        if (array_key_exists('isActive', $query)) {
            $filters['activeOnly'] = ($query['isActive'] === 'true' || $query['isActive'] === '1');
            if ($query['isActive'] === 'false' || $query['isActive'] === '0') {
                $filters['activeOnly'] = false;
            }
        }
        if (isset($query['groupPublicId']) && is_string($query['groupPublicId'])) {
            $filters['groupPublicId'] = $query['groupPublicId'];
        }
        if (isset($query['definitionPublicId']) && is_string($query['definitionPublicId'])) {
            $filters['definitionPublicId'] = $query['definitionPublicId'];
        }
        if (isset($query['scopeCode']) && is_string($query['scopeCode'])) {
            $filters['scopeCode'] = $query['scopeCode'];
        }

        return $filters;
    }
}
