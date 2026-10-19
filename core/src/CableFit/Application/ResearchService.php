<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Application;

use Closure;
use DateTimeImmutable;
use Throwable;
use TriCab\Core\CableFit\Contract\CableFitResearchWake;
use TriCab\Core\CableFit\Contract\ResearchRunRepository;
use TriCab\Core\CableFit\Contract\ResearchSourceRepository;
use TriCab\Core\CableFit\Domain\CandidateNotFound;
use TriCab\Core\CableFit\Domain\ResearchDailyLimitReached;
use TriCab\Core\CableFit\Domain\ResearchFinding;
use TriCab\Core\CableFit\Domain\ResearchInputInvalid;
use TriCab\Core\CableFit\Domain\ResearchNoSources;
use TriCab\Core\CableFit\Domain\ResearchOff;
use TriCab\Core\CableFit\Domain\ResearchRun;
use TriCab\Core\CableFit\Domain\ResearchRunActive;
use TriCab\Core\CableFit\Domain\ResearchRunNotFound;
use TriCab\Core\CableFit\Domain\ResearchSource;
use TriCab\Core\CableFit\Domain\ResearchSourceExists;
use TriCab\Core\CableFit\Domain\ResearchSourceNotFound;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

/**
 * Use cases behind the CableFit research endpoints: the source list, and queueing and reading runs.
 * Permissions (TSD.CABLEFIT.USE to read, TSD.CABLEFIT.RESEARCH to write) belong to the route; candidate
 * visibility is {@see CableFitCandidateService}'s, so a run is as visible as its candidate.
 *
 * Running the model is not done here: a request saves a queued run and signals the worker.
 */
final readonly class ResearchService
{
    private const DOMAIN = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/';

    /** @var Closure(): DateTimeImmutable */
    private Closure $clock;

    /** @var Closure(): string */
    private Closure $idGenerator;

    /**
     * @param bool                                $enabled CABLEFIT_RESEARCH_ENABLED
     * @param (Closure(): DateTimeImmutable)|null $clock
     * @param (Closure(): string)|null            $idGenerator
     * @param int                                 $dailyRunsPerUser CABLEFIT_RESEARCH_DAILY_RUNS_PER_USER: runs one user may start in a rolling 24 hours
     */
    public function __construct(
        private CableFitCandidateService $candidates,
        private ResearchSourceRepository $sources,
        private ResearchRunRepository $runs,
        private CableFitResearchWake $wake,
        private bool $enabled = false,
        ?Closure $clock = null,
        ?Closure $idGenerator = null,
        private int $dailyRunsPerUser = 10,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
        $this->idGenerator = $idGenerator ?? static fn (): string => self::newGuid();
    }

    /**
     * @return list<ResearchSource>
     */
    public function listSources(): array
    {
        return $this->sources->all();
    }

    /**
     * @return string the new source's public id
     *
     * @throws ResearchInputInvalid
     * @throws ResearchSourceExists
     */
    public function createSource(AuthenticatedUser $user, mixed $domain, mixed $name, mixed $category, mixed $notes = null): string
    {
        $domain = is_string($domain) ? strtolower(trim($domain)) : '';
        if (preg_match(self::DOMAIN, $domain) !== 1) {
            throw new ResearchInputInvalid('domain must be a bare hostname such as iec.ch (no scheme, path or port).');
        }
        [$name, $category, $notes] = $this->editable($name, $category, $notes);
        if ($this->sources->findByDomain($domain) !== null) {
            throw new ResearchSourceExists('A source for ' . $domain . ' already exists.');
        }

        $now = ($this->clock)();
        $publicId = ($this->idGenerator)();
        $this->sources->add(new ResearchSource($publicId, $domain, $name, $category, true, $notes, $now, $now), $user->userId);

        return $publicId;
    }

    /**
     * The domain cannot change; to change it, disable the source and add a new one.
     *
     * @throws ResearchInputInvalid
     * @throws ResearchSourceNotFound
     */
    public function updateSource(AuthenticatedUser $user, string $publicId, mixed $name, mixed $category, mixed $enabled, mixed $notes = null): void
    {
        if (!is_bool($enabled)) {
            throw new ResearchInputInvalid('enabled must be true or false.');
        }
        [$name, $category, $notes] = $this->editable($name, $category, $notes);
        $source = $this->sources->findByPublicId($publicId) ?? throw new ResearchSourceNotFound('Source not found.');

        $this->sources->save($source->with($name, $category, $enabled, $notes, ($this->clock)()), $user->userId);
    }

    /**
     * Queue a run for a candidate and wake the worker.
     *
     * @param array<array-key, mixed>|null $parameters the design parameters; null or empty uses the candidate's latest construction
     *
     * @return string the run's public id
     *
     * @throws ResearchOff
     * @throws CandidateNotFound
     * @throws ResearchInputInvalid
     * @throws ResearchNoSources
     * @throws ResearchRunActive
     * @throws ResearchDailyLimitReached
     */
    public function requestRun(AuthenticatedUser $user, string $candidatePublicId, mixed $application, ?array $parameters = null): string
    {
        if (!$this->enabled) {
            throw new ResearchOff('Research is switched off.');
        }
        $detail = $this->candidates->get($user, $candidatePublicId);

        $application = is_string($application) ? trim($application) : '';
        if ($application === '' || mb_strlen($application) > 2000) {
            throw new ResearchInputInvalid('application is required and at most 2000 characters.');
        }
        $parameters = $parameters === null || $parameters === [] ? $detail->construction : ResearchParameters::validate($parameters);
        if (ResearchParameters::flatten($parameters) === []) {
            throw new ResearchInputInvalid('The design has no parameters set yet; fill in some of the construction, or send parameters.');
        }
        $domains = $this->sources->enabledDomains();
        if ($domains === []) {
            throw new ResearchNoSources('No research source is enabled.');
        }

        $active = $this->runs->findActive($detail->candidate->publicId);
        if ($active !== null) {
            throw new ResearchRunActive($active->publicId);
        }

        $now = ($this->clock)();
        $publicId = ($this->idGenerator)();
        $this->runs->queueWithinLimits(ResearchRun::queued(
            $publicId,
            $detail->candidate->publicId,
            $application,
            $parameters,
            $domains,
            $user->userId,
            $user->displayName,
            $now,
        ), $this->dailyRunsPerUser, $now);

        try {
            $this->wake->signal();
        } catch (Throwable) {
            // Best effort: the run row is the record and the worker's periodic check finds it.
        }

        return $publicId;
    }

    /**
     * @return list<ResearchRun> newest first
     *
     * @throws CandidateNotFound
     */
    public function listRuns(AuthenticatedUser $user, string $candidatePublicId): array
    {
        $this->candidates->get($user, $candidatePublicId);

        return $this->runs->listByCandidate($candidatePublicId);
    }

    /**
     * @throws CandidateNotFound
     * @throws ResearchRunNotFound
     */
    public function getRun(AuthenticatedUser $user, string $candidatePublicId, string $runPublicId): ResearchRunDetail
    {
        $candidate = $this->candidates->get($user, $candidatePublicId)->candidate;
        $run = $this->runs->find($runPublicId);
        if ($run === null || $run->candidatePublicId !== $candidate->publicId) {
            throw new ResearchRunNotFound('Research run not found.');
        }

        $findings = $this->runs->findings($runPublicId);
        $recent = [];
        foreach ($findings as $finding) {
            if ($finding->withinRecentWindow($run->requestedAt)) {
                $recent[] = $finding->publicId;
            }
        }
        $rank = [ResearchFinding::CONFIDENCE_HIGH => 0, ResearchFinding::CONFIDENCE_MEDIUM => 1, ResearchFinding::CONFIDENCE_LOW => 2];
        usort($findings, static fn (ResearchFinding $a, ResearchFinding $b): int
            => (in_array($b->publicId, $recent, true) <=> in_array($a->publicId, $recent, true))
            ?: (($rank[$a->confidence] ?? 3) <=> ($rank[$b->confidence] ?? 3))
            ?: ($a->sortOrder <=> $b->sortOrder));

        return new ResearchRunDetail($run, $findings, $recent);
    }

    /**
     * @return array{0: string, 1: string, 2: string|null}
     *
     * @throws ResearchInputInvalid
     */
    private function editable(mixed $name, mixed $category, mixed $notes): array
    {
        $name = is_string($name) ? trim($name) : '';
        if ($name === '' || mb_strlen($name) > 200) {
            throw new ResearchInputInvalid('name is required and at most 200 characters.');
        }
        if (!is_string($category) || !in_array($category, ResearchSource::CATEGORIES, true)) {
            throw new ResearchInputInvalid('category must be one of ' . implode(', ', ResearchSource::CATEGORIES) . '.');
        }
        if ($notes !== null && !is_string($notes)) {
            throw new ResearchInputInvalid('notes must be text.');
        }
        $notes = $notes === null ? null : trim($notes);
        if ($notes !== null && mb_strlen($notes) > 1000) {
            throw new ResearchInputInvalid('notes is at most 1000 characters.');
        }

        return [$name, $category, $notes === '' ? null : $notes];
    }

    /** A lowercase RFC 4122 version 4 GUID, the format of the UNIQUEIDENTIFIER public_id. */
    private static function newGuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
