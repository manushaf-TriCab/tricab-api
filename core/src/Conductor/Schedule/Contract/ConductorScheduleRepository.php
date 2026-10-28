<?php

declare(strict_types=1);

namespace TriCab\Core\Conductor\Schedule\Contract;

/**
 * Read access to the conductor schedule store (core/python/conductor_schedule/schema.sql). The repository only hands back
 * typed table rows (camelCase keys, booleans as bool, JSON columns decoded); every rule that shapes an endpoint payload
 * (docs/conductor/SCHEDULE-CONTRACT.md, section 6) lives in ConductorScheduleQueryService, so the SQLite and in-memory
 * repositories cannot drift apart on behaviour.
 *
 * Every method may throw ConductorScheduleStoreUnavailable. Row order is unspecified unless stated.
 *
 * @phpstan-type RunRecord array{schemaVersion: int, builtAt: string, engineVersion: string, sourceName: string, today: string, horizonEnd: string, dataAsOf: string, demand: string, rateWindow: string, settingsHash: string, settingsSource: string, inventoryFile: string, inventoryExported: ?string, r86OrderFile: string, r86OrderFrom: string, r86OrderPrinted: string, r86OrderWeeks: float, r86InvoiceFile: string, r86InvoiceFrom: string, r86InvoicePrinted: string, r86InvoiceWeeks: float, restockItems: int, releaseItems: int, releaseRuns: int, releaseM: float, releaseKg: float, jobs: int, jobsScheduled: int, ops: int, passes: int, batches: int, lateOps: int}
 * @phpstan-type SourceRecord array{name: string, role: string, asOf: ?string, rows: ?int}
 * @phpstan-type SettingRecord array{key: string, value: mixed}
 * @phpstan-type R86Record array{doc: string, material: string, mica: int, csaMm2: float, metres: float, weeklyM: float, placedM: float, unplacedM: float}
 * @phpstan-type DemandRecord array{item: string, material: string, mica: bool, csaMm2: ?float, source: string, weeklyUseM: float, issueWeeklyM: float, issueWeekly52wM: float, unplaced: bool}
 * @phpstan-type RestockRecord array{item: string, form: string, material: string, mica: bool, csaMm2: ?float, kgPerM: ?float, weeklyUseM: float, kanbanM: float, kanbanAssumed: bool, runs52w: int, triggerM: float, physicalM: float, incomingM: float, positionM: float, weeksOnHand: ?float, releaseRuns: int, releaseM: float, releaseKg: ?float, status: string, scheduled: bool, note: ?string}
 * @phpstan-type JobRecord array{jobId: int, item: string, runNo: int, metres: float, dueDate: string, status: string}
 * @phpstan-type MachineRecord array{machine: string, posterName: string, name: string, calendar: string, calendarSource: string, efficiency: float, hoursPerWeek: float, stageOrder: int}
 * @phpstan-type OpRecord array{opId: int, item: string, pool: string, metres: float, requiredBy: string, startAt: string, endAt: string, lateDays: int, rodKg: ?float, outKg: ?float, passCount: int, stockM: float}
 * @phpstan-type PassRecord array{passId: int, opId: int, parentPassId: ?int, item: string, pool: string, depth: int, machine: string, eligible: list<string>, operation: string, quantity: float, unit: string, baseRate: float, operationFactor: float, productFactor: float, setupH: float, runH: float, startAt: string, endAt: string, batchId: ?int, stockM: float}
 * @phpstan-type BatchRecord array{batchId: int, item: string, pool: string, machine: string, operation: string, metres: float, batchRule: string, allocatedM: float, surplusM: float, baseRate: float, setupH: float, runH: float, startAt: string, endAt: string, opCount: int, reason: string}
 * @phpstan-type DrawRecord array{batchId: int, consumerKind: string, consumerId: int, metres: float}
 * @phpstan-type StockRecord array{item: string, point: string, pool: string, unit: string, onHandM: ?float, usedM: ?float, onHandKg: ?float, usedKg: ?float, incomingM: ?float, incomingKg: ?float, kgPerM: ?float, weeklyUse: ?float}
 * @phpstan-type CapacityRecord array{machine: string, week: string, availableH: float}
 * @phpstan-type LoadRecord array{machine: string, week: string, hours: float}
 * @phpstan-type IssueRecord array{item: string, kind: string, severity: string, detail: string, count: int}
 */
interface ConductorScheduleRepository
{
    /** @return RunRecord|null null when cs_run has no row */
    public function run(): ?array;

    /** @return list<SourceRecord> */
    public function sources(): array;

    /** @return list<SettingRecord> the effective settings, JSON decoded (objects as stdClass) */
    public function settings(): array;

    /** @return list<R86Record> */
    public function r86(): array;

    /** @return list<DemandRecord> */
    public function demand(): array;

    /** @return list<RestockRecord> */
    public function restock(): array;

    /** @return list<JobRecord> */
    public function jobs(): array;

    /** @return list<MachineRecord> the scheduled machines in stage order */
    public function machines(): array;

    /** @return list<OpRecord> */
    public function ops(): array;

    /** @return list<PassRecord> */
    public function passes(): array;

    /** @return list<BatchRecord> */
    public function batches(): array;

    /** @return list<DrawRecord> */
    public function draws(): array;

    /** @return list<StockRecord> */
    public function stock(): array;

    /** @return list<CapacityRecord> */
    public function capacity(): array;

    /** @return list<LoadRecord> */
    public function load(): array;

    /** @return list<IssueRecord> */
    public function issues(): array;
}
