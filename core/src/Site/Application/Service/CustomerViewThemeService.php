<?php

declare(strict_types=1);

namespace TriCab\Core\Site\Application\Service;

use JsonException;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Site\Application\DTO\CustomerViewTheme;
use TriCab\Core\Site\Contract\SiteConfigurationRepository;

final class CustomerViewThemeService
{
    public function __construct(
        private readonly SiteConfigurationRepository $configurations,
    ) {
    }

    /** @return array<string, mixed> */
    public function get(): array
    {
        return CustomerViewTheme::merge($this->decode($this->configurations->getJson(CustomerViewTheme::CONFIG_KEY)));
    }

    /**
     * @param array<string, mixed> $theme
     * @return array<string, mixed>
     */
    public function update(ActorContext $actor, array $theme): array
    {
        $merged = CustomerViewTheme::merge($theme);
        $json = json_encode($merged, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $actorUserId = $actor->userId >= 1 ? UserId::fromInt($actor->userId) : null;
        $this->configurations->upsertJson(CustomerViewTheme::CONFIG_KEY, $json, $actorUserId);

        return $merged;
    }

    /** @return array<string, mixed>|null */
    private function decode(?string $json): ?array
    {
        if ($json === null || trim($json) === '') {
            return null;
        }
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
