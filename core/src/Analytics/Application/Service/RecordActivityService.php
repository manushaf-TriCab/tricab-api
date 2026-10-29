<?php

declare(strict_types=1);

namespace TriCab\Core\Analytics\Application\Service;

use DateTimeZone;
use Throwable;
use TriCab\Core\Analytics\Application\DTO\ActivityWrite;
use TriCab\Core\Analytics\Application\Exception\ActivityRateLimited;
use TriCab\Core\Analytics\Application\Exception\AnalyticsUnavailable;
use TriCab\Core\Analytics\Application\Exception\InvalidActivityRequest;
use TriCab\Core\Analytics\Contract\ActivityThrottle;
use TriCab\Core\Analytics\Contract\LivePresenceStore;
use TriCab\Core\Analytics\Contract\UserActivityRepository;
use TriCab\Core\Analytics\Infrastructure\InMemoryLivePresenceStore;
use TriCab\Core\Analytics\Domain\ActivityModuleCatalog;
use TriCab\Core\Analytics\Domain\ActivityPlatform;
use TriCab\Core\Analytics\Domain\ActivitySignalType;
use TriCab\Core\Analytics\Domain\ReportingCalendar;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Domain\Service\StaffEligibilityPolicy;
use TriCab\Core\Shared\Time\Clock;

/**
 * Accepts activity signals from the web and mobile clients.
 *
 * Only explicit client signals count. API traffic itself (polling, token refresh, push, health checks) never
 * reaches this service, so it can never be mistaken for a person using the system.
 */
final class RecordActivityService
{
    /** How often clients send a heartbeat while the user is interacting. Must stay well inside the live window. */
    public const HEARTBEAT_SECONDS = 60;

    /** A heartbeat repeating the session's state within this window does not rewrite the session row. */
    private const SESSION_REFRESH_SECONDS = 30;

    private const RATE_LIMIT = 120;
    private const RATE_WINDOW_SECONDS = 60;

    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(
        private readonly UserActivityRepository $activity,
        private readonly ActivityThrottle $throttle,
        private readonly Clock $clock,
        private readonly bool $collectionEnabled = false,
        private readonly LivePresenceStore $presence = new InMemoryLivePresenceStore(),
    ) {
    }

    /**
     * @param array<mixed> $input
     * @return array{accepted: bool, collectionEnabled: bool, heartbeatSeconds: int}
     */
    public function record(AuthenticatedUser $user, array $input, bool $actingAs): array
    {
        $result = static fn (bool $accepted): array => [
            'accepted' => $accepted,
            'collectionEnabled' => true,
            'heartbeatSeconds' => self::HEARTBEAT_SECONDS,
        ];

        [$sessionId, $platform, $type, $module] = $this->validate($input);

        // An administrator acting as someone else is not that person using the system.
        if ($actingAs) {
            return $result(false);
        }

        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $isStaff = StaffEligibilityPolicy::isEligible($user->roles);
        if ($type->isQualifying()) {
            $this->presence->touch($user->userId, $isStaff, $platform->value, $module, $now);
        } else {
            $this->presence->forget($user->userId);
        }

        if (!$this->collectionEnabled) {
            return ['accepted' => false, 'collectionEnabled' => false, 'heartbeatSeconds' => self::HEARTBEAT_SECONDS];
        }

        if (!$this->throttle->allow('rate:' . $user->userId, self::RATE_LIMIT, self::RATE_WINDOW_SECONDS)) {
            throw new ActivityRateLimited(self::RATE_WINDOW_SECONDS);
        }

        $localDate = ReportingCalendar::localDate($now);
        $hourStart = ReportingCalendar::hourStartUtc($now);
        $qualifying = $type->isQualifying();

        $sessionKey = sprintf('session:%s:%d:%s', $sessionId, $user->userId, $module);
        $dailyKey = sprintf('daily:%s:%s:%d', $localDate, $platform->value, $user->userId);
        $hourlyKey = sprintf('hourly:%s:%s:%d', $hourStart->format('YmdH'), $platform->value, $user->userId);
        $moduleKey = sprintf('module:%s:%s:%s:%d', $localDate, $module, $platform->value, $user->userId);

        $write = new ActivityWrite(
            userId: $user->userId,
            isStaff: $isStaff,
            platform: $platform,
            sessionId: $sessionId,
            moduleCode: $module,
            type: $type,
            atUtc: $now,
            localDate: $localDate,
            hourStartUtc: $hourStart,
            writeSession: $type !== ActivitySignalType::Heartbeat || !$this->throttle->isRecorded($sessionKey),
            writeDaily: $qualifying && !$this->throttle->isRecorded($dailyKey),
            writeHourly: $qualifying && !$this->throttle->isRecorded($hourlyKey),
            writeModuleDaily: $type === ActivitySignalType::View
                || ($qualifying && !$this->throttle->isRecorded($moduleKey)),
        );

        if (!$write->writesAnything()) {
            return $result(true);
        }

        try {
            $this->activity->record($write);
        } catch (Throwable $exception) {
            throw new AnalyticsUnavailable('Activity could not be recorded: ' . $exception::class, 0, $exception);
        }

        if (!$qualifying) {
            // The next heartbeat must bring a backgrounded or ended session back to active.
            $this->throttle->forget($sessionKey);
        } else {
            $this->throttle->markRecorded($sessionKey, self::SESSION_REFRESH_SECONDS);
            $this->throttle->markRecorded($dailyKey, 26 * 3600);
            $this->throttle->markRecorded($hourlyKey, 2 * 3600);
            $this->throttle->markRecorded($moduleKey, 26 * 3600);
        }

        return $result(true);
    }

    /**
     * @param array<mixed> $input
     * @return array{0: string, 1: ActivityPlatform, 2: ActivitySignalType, 3: string}
     */
    private function validate(array $input): array
    {
        $sessionId = is_string($input['sessionId'] ?? null) ? strtolower(trim($input['sessionId'])) : '';
        if (preg_match(self::UUID_PATTERN, $sessionId) !== 1) {
            throw new InvalidActivityRequest('sessionId must be a UUID.');
        }

        $platform = is_string($input['platform'] ?? null) ? ActivityPlatform::tryFrom($input['platform']) : null;
        if ($platform === null) {
            throw new InvalidActivityRequest('platform must be web or mobile.');
        }

        $type = is_string($input['event'] ?? null) ? ActivitySignalType::tryFrom($input['event']) : null;
        if ($type === null) {
            throw new InvalidActivityRequest('event must be view, heartbeat, background or end.');
        }

        $module = is_string($input['module'] ?? null) ? strtolower(trim($input['module'])) : '';
        if (!ActivityModuleCatalog::isWellFormed($module)) {
            throw new InvalidActivityRequest('module must be a module code.');
        }

        return [$sessionId, $platform, $type, ActivityModuleCatalog::normalize($module)];
    }
}
