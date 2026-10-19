<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Application;

use Closure;
use DateTimeImmutable;
use Throwable;
use TriCab\Core\CableFit\Contract\ResearchModel;
use TriCab\Core\CableFit\Contract\ResearchRunRepository;
use TriCab\Core\CableFit\Contract\ResearchSourceRepository;
use TriCab\Core\CableFit\Domain\ResearchModelFailed;
use TriCab\Core\CableFit\Domain\ResearchNoSources;
use TriCab\Core\CableFit\Domain\ResearchRun;

/**
 * What the worker calls: claim one queued run, research it, save the outcome.
 *
 * The result is advisory and touches nothing but the run: no fit score and no design. Any finding whose
 * URL host is not an enabled source domain (or a subdomain of one) is dropped before saving, whatever the
 * model was told, and the drops are counted on the run.
 *
 * A model failure, unparseable answer or missing source list fails the run with a message that is safe to
 * show. A database failure is not caught: the claim then goes stale and a later pass takes the run again.
 */
final class ResearchRunner
{
    /** A claim older than this is a crashed worker's. */
    public const STALE_CLAIM_MINUTES = 30;

    /** @var Closure(): DateTimeImmutable */
    private Closure $clock;

    private ResearchResponseParser $parser;

    /**
     * @param (Closure(): DateTimeImmutable)|null $clock
     * @param (Closure(): string)|null            $idGenerator
     */
    public function __construct(
        private readonly ResearchRunRepository $runs,
        private readonly ResearchSourceRepository $sources,
        private readonly ResearchModel $model,
        private readonly int $maxSearches = 8,
        ?Closure $clock = null,
        ?Closure $idGenerator = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
        $this->parser = new ResearchResponseParser($idGenerator ?? static fn (): string => self::newGuid());
    }

    /**
     * Claim and process one run.
     *
     * @return ResearchRun|null the run as it ended, or null when nothing was queued
     */
    public function runNext(): ?ResearchRun
    {
        $now = ($this->clock)();
        $run = $this->runs->claimNext($now, $now->modify('-' . self::STALE_CLAIM_MINUTES . ' minutes'));
        if ($run === null) {
            return null;
        }

        $cost = null;
        try {
            $domains = $this->sources->enabledDomains();
            if ($domains === []) {
                throw new ResearchNoSources('No research source is enabled.');
            }

            $response = $this->model->research(
                ResearchPrompt::system(),
                ResearchPrompt::user($run->application, ResearchParameters::flatten($run->parameters), $domains, $this->maxSearches, $now),
                $domains,
                $this->maxSearches,
            );
            $cost = $response->cost;

            $kept = [];
            $dropped = 0;
            foreach ($this->parser->parse($response) as $finding) {
                if (ResearchSourcePolicy::allows($finding->url, $domains)) {
                    $kept[] = $finding->withSortOrder(count($kept));
                } else {
                    $dropped++;
                }
            }

            $this->runs->markSucceeded($run->publicId, $kept, $domains, $cost, $dropped, ($this->clock)());
        } catch (ResearchModelFailed | ResearchNoSources $failure) {
            $this->runs->markFailed($run->publicId, $failure->getMessage(), $cost, ($this->clock)());
        } catch (Throwable $failure) {
            $this->runs->markFailed(
                $run->publicId,
                'Unexpected error (' . (new \ReflectionClass($failure))->getShortName() . ').',
                $cost,
                ($this->clock)(),
            );
        }

        return $this->runs->find($run->publicId) ?? $run;
    }

    /** A lowercase RFC 4122 version 4 GUID. */
    private static function newGuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
