<?php

declare(strict_types=1);

namespace TriCab\Core\Conductor\Schedule\Application\Service;

use DateTimeImmutable;
use stdClass;
use TriCab\Core\Conductor\Schedule\Application\Exception\ConductorScheduleBatchNotFound;
use TriCab\Core\Conductor\Schedule\Application\Exception\ConductorScheduleInvalidRequest;
use TriCab\Core\Conductor\Schedule\Application\Exception\ConductorScheduleOpNotFound;
use TriCab\Core\Conductor\Schedule\Application\Exception\ConductorScheduleStoreUnavailable;
use TriCab\Core\Conductor\Schedule\Application\Query\IssuesFilter;
use TriCab\Core\Conductor\Schedule\Application\Query\JobsFilter;
use TriCab\Core\Conductor\Schedule\Application\Query\OpsFilter;
use TriCab\Core\Conductor\Schedule\Application\Query\RestockFilter;
use TriCab\Core\Conductor\Schedule\Contract\ConductorScheduleRepository;
use TriCab\Core\Conductor\Schedule\Vocabulary;

/**
 * Validates the request parameters (raw strings from the query string, null when absent or blank), reads table rows
 * from the repository and shapes each endpoint's camelCase payload by the rules of docs/conductor/SCHEDULE-CONTRACT.md
 * section 6.
 *
 * @phpstan-import-type RestockRecord from ConductorScheduleRepository
 * @phpstan-import-type DemandRecord from ConductorScheduleRepository
 * @phpstan-import-type JobRecord from ConductorScheduleRepository
 * @phpstan-import-type MachineRecord from ConductorScheduleRepository
 * @phpstan-import-type OpRecord from ConductorScheduleRepository
 * @phpstan-import-type PassRecord from ConductorScheduleRepository
 * @phpstan-import-type BatchRecord from ConductorScheduleRepository
 * @phpstan-import-type StockRecord from ConductorScheduleRepository
 */
final class ConductorScheduleQueryService
{
    public function __construct(
        private readonly ConductorScheduleRepository $repository,
    ) {
    }

    /** @return array<string, mixed> */
    public function run(): array
    {
        $run = $this->repository->run();

        $unplaced = ['order' => 0.0, 'invoice' => 0.0];
        foreach ($this->repository->r86() as $bucket) {
            if (isset($unplaced[$bucket['doc']])) {
                $unplaced[$bucket['doc']] += $bucket['unplacedM'];
            }
        }

        $warnings = 0;
        $infos = 0;
        $shortPeriod = false;
        foreach ($this->repository->issues() as $issue) {
            if ($issue['severity'] === 'warning') {
                $warnings++;
            } else {
                $infos++;
            }
            if ($issue['kind'] === 'r86_short_period') {
                $shortPeriod = true;
            }
        }

        $sources = $this->repository->sources();
        usort($sources, static fn (array $a, array $b): int => [$a['role'], $a['name']] <=> [$b['role'], $b['name']]);

        $settings = [];
        foreach ($this->repository->settings() as $setting) {
            $settings[self::camel($setting['key'])] = $setting['value'];
        }
        ksort($settings);

        return [
            'run' => $run === null ? null : [
                'builtAt' => $run['builtAt'],
                'engineVersion' => $run['engineVersion'],
                'sourceName' => $run['sourceName'],
                'today' => $run['today'],
                'horizonEnd' => $run['horizonEnd'],
                'dataAsOf' => $run['dataAsOf'],
                'demand' => $run['demand'],
                'rateWindow' => $run['rateWindow'],
                'settingsHash' => $run['settingsHash'],
                'settingsSource' => $run['settingsSource'],
                'schemaVersion' => $run['schemaVersion'],
                'inventoryFile' => $run['inventoryFile'],
                'inventoryExported' => $run['inventoryExported'],
            ],
            'r86' => [
                'order' => [
                    'file' => $run['r86OrderFile'] ?? null,
                    'from' => $run['r86OrderFrom'] ?? null,
                    'printed' => $run['r86OrderPrinted'] ?? null,
                    'weeks' => $run['r86OrderWeeks'] ?? null,
                    'unplacedWeeklyM' => ($run['demand'] ?? null) === 'r86-order' ? $unplaced['order'] : null,
                ],
                'invoice' => [
                    'file' => $run['r86InvoiceFile'] ?? null,
                    'from' => $run['r86InvoiceFrom'] ?? null,
                    'printed' => $run['r86InvoicePrinted'] ?? null,
                    'weeks' => $run['r86InvoiceWeeks'] ?? null,
                    // Only the file used as demand is placed on items; the other one has no unplaced figure.
                    'unplacedWeeklyM' => ($run['demand'] ?? null) === 'r86-invoice' ? $unplaced['invoice'] : null,
                ],
                'shortPeriod' => $shortPeriod,
            ],
            'counts' => [
                'restockItems' => $run['restockItems'] ?? 0,
                'releaseItems' => $run['releaseItems'] ?? 0,
                'releaseRuns' => $run['releaseRuns'] ?? 0,
                'releaseM' => $run['releaseM'] ?? 0.0,
                'releaseKg' => $run['releaseKg'] ?? 0.0,
                'jobs' => $run['jobs'] ?? 0,
                'jobsScheduled' => $run['jobsScheduled'] ?? 0,
                'ops' => $run['ops'] ?? 0,
                'passes' => $run['passes'] ?? 0,
                'batches' => $run['batches'] ?? 0,
                'lateOps' => $run['lateOps'] ?? 0,
                'issuesWarning' => $warnings,
                'issuesInfo' => $infos,
            ],
            'sources' => array_map(
                static fn (array $s): array => ['name' => $s['name'], 'role' => $s['role'], 'asOf' => $s['asOf'], 'rows' => $s['rows']],
                $sources,
            ),
            // An empty settings object must stay an object in JSON.
            'settings' => $settings === [] ? new stdClass() : $settings,
        ];
    }

    /** @return array<string, mixed> */
    public function restock(?string $status, ?string $material, ?string $scheduled): array
    {
        $filter = new RestockFilter(
            self::oneOf($status, Vocabulary::RESTOCK_STATUSES, 'status'),
            self::oneOf($material, Vocabulary::MATERIALS, 'material'),
            self::bool($scheduled, 'scheduled'),
        );

        $demand = [];
        foreach ($this->repository->demand() as $d) {
            $demand[$d['item']] = $d;
        }

        // The material filter applies to the by-size roll-up too; status and scheduled do not.
        $byMaterial = array_values(array_filter(
            $this->repository->restock(),
            static fn (array $r): bool => $filter->material === null || $r['material'] === $filter->material,
        ));
        $rows = array_values(array_filter(
            $byMaterial,
            static fn (array $r): bool => ($filter->status === null || $r['status'] === $filter->status)
                && ($filter->scheduled === null || $r['scheduled'] === $filter->scheduled),
        ));
        usort($rows, static function (array $a, array $b): int {
            $rank = static fn (string $s): int => (int) array_search($s, Vocabulary::RESTOCK_STATUSES, true);
            if ($rank($a['status']) !== $rank($b['status'])) {
                return $rank($a['status']) <=> $rank($b['status']);
            }
            if ($a['status'] === 'release') {
                // release_kg descending, NULL last
                $byKg = [$a['releaseKg'] === null ? 1 : 0, -($a['releaseKg'] ?? 0.0)] <=> [$b['releaseKg'] === null ? 1 : 0, -($b['releaseKg'] ?? 0.0)];
                if ($byKg !== 0) {
                    return $byKg;
                }
            }

            return $a['item'] <=> $b['item'];
        });

        $totals = ['items' => count($rows), 'releaseItems' => 0, 'releaseRuns' => 0, 'releaseM' => 0.0, 'releaseKg' => 0.0, 'okItems' => 0, 'noUseItems' => 0];
        foreach ($rows as $r) {
            $totals['releaseRuns'] += $r['releaseRuns'];
            $totals['releaseM'] += $r['releaseM'];
            $totals['releaseKg'] += $r['releaseKg'] ?? 0.0;
            if ($r['status'] === 'release') {
                $totals['releaseItems']++;
            } elseif ($r['status'] === 'ok') {
                $totals['okItems']++;
            } else {
                $totals['noUseItems']++;
            }
        }

        return [
            'rows' => array_map(static fn (array $r): array => self::restockRow($r, $demand[$r['item']] ?? null), $rows),
            'bySize' => $this->bySize($byMaterial),
            'totals' => $totals,
        ];
    }

    /** @return array<string, mixed> */
    public function jobs(?string $status, ?string $late, ?string $page, ?string $pageSize): array
    {
        $filter = new JobsFilter(
            self::oneOf($status, Vocabulary::JOB_STATUSES, 'status'),
            self::bool($late, 'late'),
            self::page($page),
            self::pageSize($pageSize),
        );

        $ops = [];
        foreach ($this->repository->ops() as $op) {
            $ops[$op['opId']] = $op;
        }
        $jobs = array_values(array_filter(
            $this->repository->jobs(),
            static fn (array $j): bool => ($filter->status === null || $j['status'] === $filter->status)
                && ($filter->late === null || $filter->late === (isset($ops[$j['jobId']]) && $ops[$j['jobId']]['lateDays'] > 0)),
        ));
        usort($jobs, static fn (array $a, array $b): int => $a['jobId'] <=> $b['jobId']);

        $total = count($jobs);

        return [
            'jobs' => array_map(
                static fn (array $j): array => self::jobRow($j, $ops[$j['jobId']] ?? null),
                array_slice($jobs, ($filter->page - 1) * $filter->pageSize, $filter->pageSize),
            ),
            'page' => $filter->page,
            'pageSize' => $filter->pageSize,
            'totalJobs' => $total,
            'totalPages' => self::pages($total, $filter->pageSize),
        ];
    }

    /** @return array<string, mixed> */
    public function ops(?string $machine, ?string $late, ?string $item, ?string $page, ?string $pageSize): array
    {
        $machines = $this->repository->machines();
        $filter = new OpsFilter(
            self::machine($machine, $machines),
            self::bool($late, 'late'),
            $item === null || trim($item) === '' ? null : trim($item),
            self::page($page),
            self::pageSize($pageSize),
        );

        $passes = $this->repository->passes();
        $onMachine = [];
        if ($filter->machine !== null) {
            foreach ($passes as $p) {
                if ($p['machine'] === $filter->machine) {
                    $onMachine[$p['opId']] = true;
                }
            }
        }

        $ops = array_values(array_filter(
            $this->repository->ops(),
            static fn (array $o): bool => ($filter->machine === null || isset($onMachine[$o['opId']]))
                && ($filter->late === null || $filter->late === ($o['lateDays'] > 0))
                && ($filter->item === null || strcasecmp($o['item'], $filter->item) === 0),
        ));
        usort($ops, static fn (array $a, array $b): int => $a['opId'] <=> $b['opId']);

        $total = count($ops);
        $poster = self::posterNames($machines);
        $byOp = [];
        foreach ($passes as $p) {
            $byOp[$p['opId']][] = $p;
        }

        return [
            'ops' => array_map(
                static fn (array $o): array => self::opRow($o, $byOp[$o['opId']] ?? [], $poster),
                array_slice($ops, ($filter->page - 1) * $filter->pageSize, $filter->pageSize),
            ),
            'page' => $filter->page,
            'pageSize' => $filter->pageSize,
            'totalOps' => $total,
            'totalPages' => self::pages($total, $filter->pageSize),
        ];
    }

    /** @return array<string, mixed> */
    public function op(?string $id): array
    {
        $opId = self::requiredId($id, 'CONDUCTOR_OP_REQUIRED');
        foreach ($this->repository->ops() as $op) {
            if ($op['opId'] === $opId) {
                $passes = array_values(array_filter($this->repository->passes(), static fn (array $p): bool => $p['opId'] === $opId));

                return ['op' => self::opRow($op, $passes, self::posterNames($this->repository->machines()))];
            }
        }

        throw new ConductorScheduleOpNotFound('Unknown operation.');
    }

    /** @return array<string, mixed> */
    public function batches(): array
    {
        $batches = $this->repository->batches();
        usort($batches, static fn (array $a, array $b): int => $a['batchId'] <=> $b['batchId']);
        $poster = self::posterNames($this->repository->machines());
        $kgPerM = self::kgPerM($this->repository->stock());

        return ['batches' => array_map(static fn (array $b): array => self::batchRow($b, $poster, $kgPerM), $batches)];
    }

    /** @return array<string, mixed> */
    public function batch(?string $id): array
    {
        $batchId = self::requiredId($id, 'CONDUCTOR_BATCH_REQUIRED');
        $batches = [];
        foreach ($this->repository->batches() as $b) {
            $batches[$b['batchId']] = $b;
        }
        $batch = $batches[$batchId] ?? throw new ConductorScheduleBatchNotFound('Unknown batch.');

        $ops = [];
        foreach ($this->repository->ops() as $op) {
            $ops[$op['opId']] = $op;
        }
        $draws = [];
        $feeders = [];
        foreach ($this->repository->draws() as $draw) {
            if ($draw['batchId'] === $batchId) {
                $draws[] = $draw;
            }
            if ($draw['consumerKind'] === 'batch' && $draw['consumerId'] === $batchId) {
                $feeders[] = $draw;
            }
        }
        usort($draws, static fn (array $a, array $b): int => [$a['consumerKind'], $a['consumerId']] <=> [$b['consumerKind'], $b['consumerId']]);
        usort($feeders, static fn (array $a, array $b): int => $a['batchId'] <=> $b['batchId']);

        $row = self::batchRow($batch, self::posterNames($this->repository->machines()), self::kgPerM($this->repository->stock()));
        $row['draws'] = array_map(
            static fn (array $d): array => [
                'consumerKind' => $d['consumerKind'],
                'consumerId' => $d['consumerId'],
                'metres' => $d['metres'],
                'item' => $d['consumerKind'] === 'op'
                    ? ($ops[$d['consumerId']]['item'] ?? null)
                    : ($batches[$d['consumerId']]['item'] ?? null),
            ],
            $draws,
        );
        $row['feeders'] = array_map(
            static fn (array $d): array => ['batchId' => $d['batchId'], 'item' => $batches[$d['batchId']]['item'] ?? null, 'metres' => $d['metres']],
            $feeders,
        );

        return ['batch' => $row];
    }

    /** @return array<string, mixed> */
    public function machineLoad(?string $weeks): array
    {
        $count = self::weeks($weeks);
        $run = $this->repository->run() ?? throw new ConductorScheduleStoreUnavailable('The store has no run.');

        $today = new DateTimeImmutable($run['today']);
        $monday = $today->modify('-' . ((int) $today->format('N') - 1) . ' days');
        $weekStarts = [];
        for ($i = 0; $i < $count; $i++) {
            $weekStarts[] = $monday->modify('+' . (7 * $i) . ' days')->format('Y-m-d');
        }

        $available = [];
        foreach ($this->repository->capacity() as $c) {
            $available[$c['machine']][$c['week']] = $c['availableH'];
        }
        $load = [];
        foreach ($this->repository->load() as $l) {
            $load[$l['machine']][$l['week']] = $l['hours'];
        }

        $machines = $this->repository->machines();
        usort($machines, static fn (array $a, array $b): int => $a['stageOrder'] <=> $b['stageOrder']);

        $out = [];
        foreach ($machines as $m) {
            $weekRows = [];
            $totalAvailable = 0.0;
            $totalLoad = 0.0;
            foreach ($weekStarts as $week) {
                $a = $available[$m['machine']][$week] ?? 0.0;
                $h = $load[$m['machine']][$week] ?? 0.0;
                $totalAvailable += $a;
                $totalLoad += $h;
                $weekRows[] = ['week' => $week, 'availableHours' => $a, 'loadHours' => $h, 'utilisation' => $a > 0.0 ? $h / $a : null];
            }
            $out[] = [
                'machine' => $m['machine'],
                'posterName' => $m['posterName'],
                'name' => $m['name'],
                'calendarSource' => $m['calendarSource'],
                'efficiency' => $m['efficiency'],
                'hoursPerWeek' => $m['hoursPerWeek'],
                'weeks' => $weekRows,
                'totalAvailableHours' => $totalAvailable,
                'totalLoadHours' => $totalLoad,
                'utilisation' => $totalAvailable > 0.0 ? $totalLoad / $totalAvailable : null,
            ];
        }

        return ['weeks' => $count, 'from' => $weekStarts[0], 'machines' => $out];
    }

    /** @return array<string, mixed> */
    public function stock(): array
    {
        $rod = null;
        $rows = [];
        foreach ($this->repository->stock() as $s) {
            if ($s['point'] === 'rod') {
                $rod ??= $s;
            } else {
                $rows[] = $s;
            }
        }
        $rank = static function (string $point): int {
            $at = array_search($point, Vocabulary::STOCK_POINTS, true);

            return $at === false ? count(Vocabulary::STOCK_POINTS) : $at;
        };
        usort($rows, static fn (array $a, array $b): int => [$rank($a['point']), $a['item']] <=> [$rank($b['point']), $b['item']]);

        $rodRow = null;
        if ($rod !== null) {
            $warnWeeks = null;
            foreach ($this->repository->settings() as $setting) {
                if ($setting['key'] === 'rod_warn_weeks' && is_numeric($setting['value'])) {
                    $warnWeeks = (float) $setting['value'];
                }
            }
            $onHand = $rod['onHandKg'];
            $use = $rod['weeklyUse'];
            $cover = $onHand !== null && $use !== null && $use > 0.0 ? $onHand / $use : null;
            $rodRow = [
                'item' => $rod['item'],
                'onHandKg' => $onHand,
                'incomingKg' => $rod['incomingKg'],
                'weeklyUseKg' => $use,
                'weeksCover' => $cover,
                'warnWeeks' => $warnWeeks,
                'low' => $cover !== null && $warnWeeks !== null && $cover < $warnWeeks,
            ];
        }

        return [
            'rod' => $rodRow,
            'rows' => array_map(static fn (array $s): array => [
                'item' => $s['item'],
                'point' => $s['point'],
                'pool' => $s['pool'],
                'unit' => $s['unit'],
                'onHandM' => $s['onHandM'],
                'usedM' => $s['usedM'],
                'onHandKg' => $s['onHandKg'],
                'usedKg' => $s['usedKg'],
                'incomingM' => $s['incomingM'],
                'kgPerM' => $s['kgPerM'],
            ], $rows),
        ];
    }

    /** @return array<string, mixed> */
    public function issues(?string $kind, ?string $severity): array
    {
        $filter = new IssuesFilter(
            self::oneOf($kind, Vocabulary::ISSUE_KINDS, 'kind'),
            self::oneOf($severity, Vocabulary::SEVERITIES, 'severity'),
        );

        $rank = static fn (string $s): int => (int) array_search($s, Vocabulary::SEVERITIES, true);
        $issues = array_values(array_filter(
            $this->repository->issues(),
            static fn (array $i): bool => ($filter->kind === null || $i['kind'] === $filter->kind)
                && ($filter->severity === null || $i['severity'] === $filter->severity),
        ));
        usort($issues, static fn (array $a, array $b): int => [$rank($a['severity']), $a['kind'], $a['item']] <=> [$rank($b['severity']), $b['kind'], $b['item']]);

        $byKind = [];
        foreach ($issues as $i) {
            $byKind[$i['kind']] ??= ['kind' => $i['kind'], 'severity' => $i['severity'], 'items' => 0, 'count' => 0];
            $byKind[$i['kind']]['items']++;
            $byKind[$i['kind']]['count'] += $i['count'];
        }
        $byKind = array_values($byKind);
        usort($byKind, static fn (array $a, array $b): int => [$rank($a['severity']), $a['kind']] <=> [$rank($b['severity']), $b['kind']]);

        return [
            'issues' => array_map(
                static fn (array $i): array => ['item' => $i['item'], 'kind' => $i['kind'], 'severity' => $i['severity'], 'detail' => $i['detail'], 'count' => $i['count']],
                $issues,
            ),
            'byKind' => $byKind,
        ];
    }

    /**
     * @param list<RestockRecord> $rows restock rows after the material filter
     * @return list<array<string, mixed>>
     */
    private function bySize(array $rows): array
    {
        $r86 = [];
        foreach ($this->repository->r86() as $b) {
            $key = $b['material'] . '|' . $b['csaMm2'];
            $r86[$key][$b['doc']] = ($r86[$key][$b['doc']] ?? 0.0) + $b['weeklyM'];
        }
        $demand = [];
        foreach ($this->repository->demand() as $d) {
            $demand[$d['item']] = $d;
        }

        $groups = [];
        foreach ($rows as $r) {
            if ($r['form'] === 'spool' || $r['csaMm2'] === null) {
                continue;
            }
            $key = $r['material'] . '|' . $r['csaMm2'];
            $groups[$key] ??= [
                'material' => $r['material'], 'csaMm2' => $r['csaMm2'], 'weeklyUseM' => 0.0, 'issueWeeklyM' => 0.0,
                'r86OrderedWeeklyM' => $r86[$key]['order'] ?? 0.0, 'r86InvoicedWeeklyM' => $r86[$key]['invoice'] ?? 0.0,
                'triggerM' => 0.0, 'physicalM' => 0.0, 'incomingM' => 0.0, 'releaseM' => 0.0,
            ];
            $groups[$key]['weeklyUseM'] += $r['weeklyUseM'];
            $groups[$key]['issueWeeklyM'] += $demand[$r['item']]['issueWeeklyM'] ?? 0.0;
            $groups[$key]['triggerM'] += $r['triggerM'];
            $groups[$key]['physicalM'] += $r['physicalM'];
            $groups[$key]['incomingM'] += $r['incomingM'];
            $groups[$key]['releaseM'] += $r['releaseM'];
        }
        $out = array_values($groups);
        usort($out, static fn (array $a, array $b): int => [$a['material'], $a['csaMm2']] <=> [$b['material'], $b['csaMm2']]);

        return $out;
    }

    /**
     * @param RestockRecord $r
     * @param DemandRecord|null $d
     * @return array<string, mixed>
     */
    private static function restockRow(array $r, ?array $d): array
    {
        return [
            'item' => $r['item'],
            'form' => $r['form'],
            'material' => $r['material'],
            'mica' => $r['mica'],
            'csaMm2' => $r['csaMm2'],
            'kgPerM' => $r['kgPerM'],
            'weeklyUseM' => $r['weeklyUseM'],
            'demandSource' => $d['source'] ?? null,
            'unplaced' => $d['unplaced'] ?? false,
            'issueWeeklyM' => $d['issueWeeklyM'] ?? null,
            'kanbanM' => $r['kanbanM'],
            'kanbanAssumed' => $r['kanbanAssumed'],
            'runs52w' => $r['runs52w'],
            'triggerM' => $r['triggerM'],
            'physicalM' => $r['physicalM'],
            'incomingM' => $r['incomingM'],
            'positionM' => $r['positionM'],
            'weeksOnHand' => $r['weeksOnHand'],
            'releaseRuns' => $r['releaseRuns'],
            'releaseM' => $r['releaseM'],
            'releaseKg' => $r['releaseKg'],
            'status' => $r['status'],
            'scheduled' => $r['scheduled'],
            'note' => $r['note'],
        ];
    }

    /**
     * @param JobRecord $j
     * @param OpRecord|null $o
     * @return array<string, mixed>
     */
    private static function jobRow(array $j, ?array $o): array
    {
        return [
            'jobId' => $j['jobId'],
            'item' => $j['item'],
            'runNo' => $j['runNo'],
            'metres' => $j['metres'],
            'dueDate' => $j['dueDate'],
            'status' => $j['status'],
            'startAt' => $o['startAt'] ?? null,
            'endAt' => $o['endAt'] ?? null,
            'lateDays' => $o['lateDays'] ?? null,
            'rodKg' => $o['rodKg'] ?? null,
            'outKg' => $o['outKg'] ?? null,
            'passCount' => $o['passCount'] ?? 0,
            'stockM' => $o['stockM'] ?? 0.0,
        ];
    }

    /**
     * @param OpRecord $o
     * @param list<PassRecord> $passes
     * @param array<string, string> $poster D365 id => poster name
     * @return array<string, mixed>
     */
    private static function opRow(array $o, array $passes, array $poster): array
    {
        usort($passes, static fn (array $a, array $b): int => $a['passId'] <=> $b['passId']);

        return [
            'opId' => $o['opId'],
            'item' => $o['item'],
            'pool' => $o['pool'],
            'metres' => $o['metres'],
            'requiredBy' => $o['requiredBy'],
            'startAt' => $o['startAt'],
            'endAt' => $o['endAt'],
            'lateDays' => $o['lateDays'],
            'rodKg' => $o['rodKg'],
            'outKg' => $o['outKg'],
            'impliedYield' => $o['rodKg'] !== null && $o['outKg'] !== null && $o['rodKg'] !== 0.0 ? $o['outKg'] / $o['rodKg'] : null,
            'stockMetres' => $o['stockM'],
            'passCount' => $o['passCount'],
            'passes' => array_map(static fn (array $p): array => [
                'passId' => $p['passId'],
                'parentPassId' => $p['parentPassId'],
                'item' => $p['item'],
                'pool' => $p['pool'],
                'depth' => $p['depth'],
                'machine' => $p['machine'],
                'posterName' => $poster[$p['machine']] ?? $p['machine'],
                'eligible' => $p['eligible'],
                'operation' => $p['operation'],
                'quantity' => $p['quantity'],
                'unit' => $p['unit'],
                'setupHours' => $p['setupH'],
                'runHours' => $p['runH'],
                'startAt' => $p['startAt'],
                'endAt' => $p['endAt'],
                'batchId' => $p['batchId'],
                'stockMetres' => $p['stockM'],
            ], $passes),
        ];
    }

    /**
     * @param BatchRecord $b
     * @param array<string, string> $poster
     * @param array<string, float> $kgPerM item => kg per metre
     * @return array<string, mixed>
     */
    private static function batchRow(array $b, array $poster, array $kgPerM): array
    {
        return [
            'batchId' => $b['batchId'],
            'item' => $b['item'],
            'pool' => $b['pool'],
            'machine' => $b['machine'],
            'posterName' => $poster[$b['machine']] ?? $b['machine'],
            'operation' => $b['operation'],
            'metres' => $b['metres'],
            'batchRule' => $b['batchRule'],
            'allocatedMetres' => $b['allocatedM'],
            'surplusMetres' => $b['surplusM'],
            'setupHours' => $b['setupH'],
            'runHours' => $b['runH'],
            'startAt' => $b['startAt'],
            'endAt' => $b['endAt'],
            'opCount' => $b['opCount'],
            'reason' => $b['reason'],
            'weightKg' => isset($kgPerM[$b['item']]) ? $b['metres'] * $kgPerM[$b['item']] : null,
        ];
    }

    /**
     * @param list<StockRecord> $stock
     * @return array<string, float>
     */
    private static function kgPerM(array $stock): array
    {
        $out = [];
        foreach ($stock as $s) {
            if ($s['kgPerM'] !== null) {
                $out[$s['item']] = $s['kgPerM'];
            }
        }

        return $out;
    }

    /**
     * @param list<MachineRecord> $machines
     * @return array<string, string>
     */
    private static function posterNames(array $machines): array
    {
        $out = [];
        foreach ($machines as $m) {
            $out[$m['machine']] = $m['posterName'];
        }

        return $out;
    }

    private static function camel(string $key): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
    }

    private static function pages(int $total, int $pageSize): int
    {
        return max(1, intdiv($total + $pageSize - 1, $pageSize));
    }

    /**
     * @param list<string> $allowed
     */
    private static function oneOf(?string $raw, array $allowed, string $name): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $value = strtolower(trim($raw));
        if (!in_array($value, $allowed, true)) {
            $last = array_pop($allowed);
            $message = count($allowed) > 6
                ? $name . ' must be one of the issue kinds in the contract.'
                : $name . ' must be ' . ($allowed === [] ? (string) $last : implode(', ', $allowed) . ' or ' . $last) . '.';
            throw new ConductorScheduleInvalidRequest('CONDUCTOR_PARAM_INVALID', $message);
        }

        return $value;
    }

    private static function bool(?string $raw, string $name): ?bool
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        return match (strtolower(trim($raw))) {
            'true' => true,
            'false' => false,
            default => throw new ConductorScheduleInvalidRequest('CONDUCTOR_PARAM_INVALID', $name . ' must be true or false.'),
        };
    }

    /**
     * @param list<MachineRecord> $machines
     */
    private static function machine(?string $raw, array $machines): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        foreach ($machines as $m) {
            if (strcasecmp($m['machine'], trim($raw)) === 0) {
                return $m['machine'];
            }
        }

        throw new ConductorScheduleInvalidRequest('CONDUCTOR_PARAM_INVALID', 'machine must be a scheduled machine id.');
    }

    private static function requiredId(?string $raw, string $missingCode): int
    {
        if ($raw === null || trim($raw) === '') {
            throw new ConductorScheduleInvalidRequest($missingCode, 'id is required.');
        }
        if (preg_match('/^\d{1,9}$/', trim($raw)) !== 1) {
            throw new ConductorScheduleInvalidRequest('CONDUCTOR_PARAM_INVALID', 'id must be a whole number.');
        }

        return (int) trim($raw);
    }

    private static function weeks(?string $raw): int
    {
        if ($raw === null || trim($raw) === '') {
            return Vocabulary::LOAD_DEFAULT_WEEKS;
        }
        foreach (Vocabulary::LOAD_WEEKS as $weeks) {
            if (trim($raw) === (string) $weeks) {
                return $weeks;
            }
        }

        throw new ConductorScheduleInvalidRequest('CONDUCTOR_PARAM_INVALID', 'weeks must be 4, 8 or 26.');
    }

    private static function page(?string $raw): int
    {
        if ($raw === null) {
            return 1;
        }
        if (preg_match('/^\d{1,9}$/', $raw) !== 1 || (int) $raw < 1) {
            throw new ConductorScheduleInvalidRequest('CONDUCTOR_PARAM_INVALID', 'page must be a whole number of 1 or more.');
        }

        return (int) $raw;
    }

    private static function pageSize(?string $raw): int
    {
        if ($raw === null) {
            return Vocabulary::DEFAULT_PAGE_SIZE;
        }
        $max = Vocabulary::MAX_PAGE_SIZE;
        if (preg_match('/^\d{1,9}$/', $raw) !== 1 || (int) $raw < 1 || (int) $raw > $max) {
            throw new ConductorScheduleInvalidRequest('CONDUCTOR_PARAM_INVALID', 'pageSize must be a whole number from 1 to ' . $max . '.');
        }

        return (int) $raw;
    }
}
