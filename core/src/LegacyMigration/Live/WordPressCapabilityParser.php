<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Live;

use TriCab\Core\LegacyMigration\Support\UserClassification;

final class WordPressCapabilityParser
{
    /**
     * @return list<string>
     */
    public function rolesFromCapabilities(?string $serialized): array
    {
        if ($serialized === null || trim($serialized) === '') {
            return [];
        }
        $parsed = @unserialize($serialized, ['allowed_classes' => false]);
        if (!is_array($parsed)) {
            return [];
        }
        $roles = [];
        foreach ($parsed as $key => $enabled) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if ($enabled) {
                $roles[] = strtolower($key);
            }
        }

        return array_values(array_unique($roles));
    }

    /**
     * @return list<string>
     */
    public function roleSlugsFromWpUserRoles(?string $serialized): array
    {
        if ($serialized === null || trim($serialized) === '') {
            return [];
        }
        $parsed = @unserialize($serialized, ['allowed_classes' => false]);
        if (!is_array($parsed)) {
            return [];
        }
        $slugs = [];
        foreach (array_keys($parsed) as $slug) {
            if (is_string($slug) && $slug !== '') {
                $slugs[] = strtolower($slug);
            }
        }

        return array_values(array_unique($slugs));
    }

    /**
     * @param list<string> $roles
     * @return list<string>
     */
    public function unknownRoles(array $roles): array
    {
        $unknown = [];
        foreach ($roles as $role) {
            if (!in_array($role, UserClassification::KNOWN_ROLE_SLUGS, true)) {
                $unknown[] = $role;
            }
        }

        return $unknown;
    }
}
