<?php

declare(strict_types=1);

namespace TriCab\Core\ProductFinder\Contract;

use TriCab\Core\ProductFinder\Application\DTO\ProductFinderQuery;
use TriCab\Core\ProductFinder\Application\DTO\ProductFinderResult;

/**
 * Turns a stated cable requirement into a sized TriCab product code.
 *
 * The rules behind this are Python, in `core/python/tricab_cable_rules/`, and
 * the implementation that reaches them is an HTTP hop to a localhost process
 * (`TriCab\Api\ProductFinder\HttpProductFinderClient`). None of that is visible
 * here on purpose: the same split as `CableCalculatorEngine`, whose contract
 * says nothing about the legacy CLI behind `LegacyCliTriCalcEngine`.
 *
 * **This is not the cable calculator.** The calculator is locked to legacy
 * parity — see `docs/cable-calculator/EXACT-LEGACY-PARITY-REPORT.md` — and its
 * `CableStyleResolver` knows 19 styles. The finder is the corrected surface,
 * covering 114, and where the two disagree the difference is a frozen legacy
 * behaviour rather than a bug in either. They are not interchangeable and
 * neither should be "fixed" to match the other.
 *
 * Implementations must not throw for an unreachable service: return a result
 * carrying `sizing: unavailable` and let the caller answer with what it has.
 */
interface ProductFinderClient
{
    public function find(ProductFinderQuery $query): ProductFinderResult;

    /**
     * Whether the finder is answering right now. Cheap enough to call per
     * request; implementations cache.
     */
    public function isAvailable(): bool;

    /**
     * The styles the finder accepts, in code order. The Style field offers
     * exactly these and the finder rejects anything else, so both sides read
     * one register. Empty when the finder is down — callers must cope.
     *
     * @return list<array{code: string, title: string}>
     */
    public function styles(): array;
}
