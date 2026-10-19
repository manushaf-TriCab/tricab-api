<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Npr\Contract\ManagerRouteRepository;
use TriCab\Core\Npr\Contract\NprBranchDirectory;
use TriCab\Core\Npr\Domain\Exception\InvalidNprInput;
use TriCab\Core\Npr\Domain\ManagerRoute;
use TriCab\Core\Npr\Domain\NewManagerRoute;
use TriCab\Core\Npr\Domain\NprPerson;
use TriCab\Core\Npr\Domain\Exception\NprWorkflowRefused;
use TriCab\Core\Shared\Time\Clock;

/**
 * Which manager endorses a branch's requests (plan N2.1; port of the portal's
 * ManagerRoutingService with the routes held in `[npr].[ManagerRoutes]`).
 *
 * The key is the requester's branch plus an effective-dated delegate override,
 * which wins while it is active. A manager who is no longer an active user does
 * not count: with no usable route a submission is blocked, never sent to TSD.
 * Overlapping routes of one kind are refused on write. Dates are whole UTC days.
 */
final class ManagerRoutingService
{
    public function __construct(
        private readonly ManagerRouteRepository $routes,
        private readonly NprPeople $people,
        private readonly Clock $clock,
    ) {
    }

    /** The route that applies to the branch today, or null. */
    public function resolve(string $branch, ?DateTimeImmutable $at = null): ?ManagerRoute
    {
        $branch = trim($branch);
        if ($branch === '' || strcasecmp($branch, NprBranchDirectory::UNASSIGNED) === 0) {
            return null;
        }
        foreach ($this->routes->activeFor($branch, $at ?? $this->clock->now()) as $route) {
            if ($this->people->isActive($route->manager->userId)) {
                return $route;
            }
        }

        return null;
    }

    /** @return list<ManagerRoute> */
    public function list(): array
    {
        return $this->routes->all();
    }

    /**
     * @param array<mixed> $body `{branch, managerUserId|managerEmail, isDelegate?, validFrom?, validTo?}`
     * @throws InvalidNprInput
     * @throws NprWorkflowRefused
     */
    public function create(AuthenticatedUser $admin, array $body): ManagerRoute
    {
        $branch = self::text($body['branch'] ?? null);
        if ($branch === '' || mb_strlen($branch) > 64 || strcasecmp($branch, NprBranchDirectory::UNASSIGNED) === 0) {
            throw new InvalidNprInput('branch is required (up to 64 characters).');
        }
        $from = self::date($body['validFrom'] ?? null, 'validFrom') ?? $this->today();
        $to = self::date($body['validTo'] ?? null, 'validTo');
        if ($to !== null && $to < $from) {
            throw new InvalidNprInput('validTo cannot be before validFrom.');
        }
        $isDelegate = ($body['isDelegate'] ?? false) === true;

        return $this->routes->create(new NewManagerRoute($branch, $this->manager($body), $isDelegate, $from, $to, $admin->userId));
    }

    /**
     * End a route (`{validTo}`, null re-opens it) or replace its manager
     * (`{replacement: {managerUserId|managerEmail, validFrom?, validTo?}, validTo?}`):
     * the old route ends the day before the replacement starts, unless `validTo` is given.
     *
     * @param array<mixed> $body
     * @return list<ManagerRoute>|null the changed routes, old first; null when there is no such route
     * @throws InvalidNprInput
     * @throws NprWorkflowRefused
     */
    public function update(AuthenticatedUser $admin, int $id, array $body): ?array
    {
        $route = $this->routes->find($id);
        if ($route === null) {
            return null;
        }
        $replacement = $body['replacement'] ?? null;

        if ($replacement === null) {
            if (!array_key_exists('validTo', $body)) {
                throw new InvalidNprInput('validTo is required: a date to end the route, or null to leave it open.');
            }
            $to = self::date($body['validTo'], 'validTo');
            if ($to !== null && $to < $route->validFrom) {
                throw new InvalidNprInput('validTo cannot be before the route starts.');
            }

            return [$this->routes->setValidTo($id, $to) ?? $route];
        }

        if (!is_array($replacement)) {
            throw new InvalidNprInput('replacement must be an object.');
        }
        $newFrom = self::date($replacement['validFrom'] ?? null, 'replacement.validFrom') ?? $this->today();
        $newTo = self::date($replacement['validTo'] ?? null, 'replacement.validTo');
        if ($newTo !== null && $newTo < $newFrom) {
            throw new InvalidNprInput('replacement.validTo cannot be before replacement.validFrom.');
        }
        $oldTo = array_key_exists('validTo', $body) ? self::date($body['validTo'], 'validTo') : $newFrom->modify('-1 day');
        if ($oldTo === null || $oldTo < $route->validFrom) {
            throw new InvalidNprInput('The route being replaced must end on or after the day it starts, and before the replacement does.');
        }
        $manager = $this->manager($replacement);

        // Check the new window against every other route first, with the old one already ended.
        foreach ($this->routes->all() as $other) {
            $window = $other->id === $route->id ? $oldTo : $other->validTo;
            if (strcasecmp($other->branch, $route->branch) === 0 && $other->isDelegate === $route->isDelegate && $this->shares($other, $window, $newFrom, $newTo)) {
                throw new NprWorkflowRefused(NprWorkflowRefused::ROUTE_OVERLAP, sprintf("The replacement overlaps another active route for '%s'.", $route->branch));
            }
        }

        $ended = $this->routes->setValidTo($id, $oldTo) ?? $route;
        try {
            $created = $this->routes->create(new NewManagerRoute($route->branch, $manager, $route->isDelegate, $newFrom, $newTo, $admin->userId));
        } catch (NprWorkflowRefused $refused) {
            $this->routes->setValidTo($id, $route->validTo);
            throw $refused;
        }

        return [$ended, $created];
    }

    /** Whether the route, with its end replaced by `$validTo`, still shares a day with the window. */
    private function shares(ManagerRoute $route, ?DateTimeImmutable $validTo, DateTimeImmutable $from, ?DateTimeImmutable $to): bool
    {
        $far = new DateTimeImmutable('9999-12-31', new DateTimeZone('UTC'));

        return $route->validFrom <= ($to ?? $far) && $from <= ($validTo ?? $far);
    }

    /** @param array<mixed> $body */
    private function manager(array $body): NprPerson
    {
        $userId = $body['managerUserId'] ?? null;
        $email = $body['managerEmail'] ?? null;
        if (!is_int($userId) && !is_string($email)) {
            throw new InvalidNprInput('managerUserId or managerEmail is required.');
        }
        $user = $this->people->find(is_int($userId) ? $userId : null, is_string($email) ? $email : null);
        if ($user === null || !$user->status()->isActive()) {
            throw new InvalidNprInput('The manager must be an active platform user.');
        }

        return NprPeople::person($user);
    }

    private function today(): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /** @throws InvalidNprInput */
    private static function date(mixed $value, string $field): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        $parsed = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC')) : false;
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new InvalidNprInput($field . ' must be a date as YYYY-MM-DD.');
        }

        return $parsed;
    }
}
