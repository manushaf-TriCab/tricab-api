<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\Service;

use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\QuoteOrder\Contract\QuoteLegacyOperatorIdResolver;
use TriCab\Core\QuoteOrder\Contract\QuoteLineLegacyMappingResolver;
use TriCab\Core\QuoteOrder\Contract\QuoteWriteCorrelationRepository;
use TriCab\Core\QuoteOrder\Contract\QuoteWriteIdempotencyStore;
use TriCab\Core\QuoteOrder\Infrastructure\NullQuoteWriteCorrelationRepository;
use TriCab\Core\QuoteOrder\Infrastructure\NullQuoteWriteIdempotencyStore;
use TriCab\Core\QuoteOrder\Live\LegacyQuoteWriteConfig;
use TriCab\Core\QuoteOrder\Live\NonProductionQuoteWriteSafety;
use TriCab\Core\QuoteOrder\Module;

/**
 * Readiness check for Phase 4C first non-prod write. Never mutates quote tables.
 *
 * Distinguishes:
 * - infrastructureReady (creds, _dev suffix, idempotency, correlation)
 * - domainReady (operator, QUOTE.CREATE, line mapping, customer fixture)
 * - writeEnabled (flag)
 * - readyForControlledEnablement = infrastructureReady && domainReady && !writeEnabled
 *
 * @phpstan-type PreflightResult array{
 *   infrastructureReady: bool,
 *   domainReady: bool,
 *   writeEnabled: bool,
 *   readyForControlledEnablement: bool,
 *   readyExceptWriteFlag: bool,
 *   writeFlagEnabled: bool,
 *   tableSuffix: string|null,
 *   checks: array<string, array{status: string, detail: string}>,
 *   blocking: list<string>,
 *   infrastructureBlocking: list<string>,
 *   domainBlocking: list<string>
 * }
 */
final class QuoteWritePreflightService
{
    private const INFRASTRUCTURE_CHECKS = [
        'WRITE_CREDENTIALS',
        'TABLE_SUFFIX',
        'DEV_GUARD',
        'IDEMPOTENCY_STORE',
        'CORRELATION',
    ];

    private const DOMAIN_CHECKS = [
        'QUOTE_CREATE',
        'OPERATOR',
        'LINE_MAPPING',
        'PM_NOT_MARGIN',
        'CUSTOMER_FIXTURE',
    ];

    public function __construct(
        private readonly LegacyQuoteWriteConfig $writeConfig,
        private readonly QuoteWriteIdempotencyStore $idempotency,
        private readonly QuoteWriteCorrelationRepository $correlation,
        private readonly QuoteLegacyOperatorIdResolver $operators,
        private readonly QuoteLineLegacyMappingResolver $lineMapping,
        private readonly NonProductionQuoteWriteSafety $writeSafety = new NonProductionQuoteWriteSafety(),
    ) {
    }

    /**
     * @param array{
     *   variantPublicId?: string,
     *   variantCode?: string,
     *   length?: string,
     *   customerPublicId?: string
     * } $fixture
     * @return PreflightResult
     */
    public function run(AuthenticatedUser $actor, array $fixture = []): array
    {
        $checks = [];

        $checks['WRITE_FLAG'] = [
            'status' => $this->writeConfig->enabled ? 'ON' : 'OFF_INTENTIONALLY',
            'detail' => $this->writeConfig->enabled
                ? 'Write flag is ON'
                : 'Intentionally disabled until Phase 4C controlled enablement',
        ];

        $suffix = $this->writeConfig->tableSuffixConfigured
            ? (string) $this->writeConfig->tableSuffix
            : null;
        // For controlled enablement prep, suffix must be configured as _dev even while flag is OFF.
        $suffixOk = $suffix === '_dev';
        $checks['TABLE_SUFFIX'] = [
            'status' => $suffixOk ? 'READY' : 'NOT_READY',
            'detail' => $suffix === null
                ? 'QUOTES_ORDERS_LEGACY_WRITE_TABLE_SUFFIX not configured (need _dev)'
                : ('configured=' . $suffix),
        ];

        $credsOk = $this->writeConfig->isConfigured();
        $checks['WRITE_CREDENTIALS'] = [
            'status' => $credsOk ? 'READY' : 'NOT_READY',
            'detail' => $credsOk
                ? 'QUOTES_ORDERS_LEGACY_WRITE_* present (secrets not shown)'
                : 'dedicated WRITE_* credentials unset; LEGACY_TC_* must not be reused',
        ];

        try {
            if ($this->writeConfig->enabled) {
                $this->writeSafety->assertDevTableSuffixRequired($this->writeConfig);
            }
            $devGuardOk = $suffixOk || !$this->writeConfig->enabled;
            $checks['DEV_GUARD'] = [
                'status' => $devGuardOk ? 'READY' : 'NOT_READY',
                'detail' => 'NonProductionQuoteWriteSafety requires suffix=_dev when enabled',
            ];
        } catch (\Throwable $e) {
            $checks['DEV_GUARD'] = [
                'status' => 'NOT_READY',
                'detail' => $e::class,
            ];
        }

        $idemOk = !($this->idempotency instanceof NullQuoteWriteIdempotencyStore);
        $checks['IDEMPOTENCY_STORE'] = [
            'status' => $idemOk ? 'READY' : 'NOT_READY',
            'detail' => $idemOk ? $this->idempotency::class : 'NullQuoteWriteIdempotencyStore (migration/wiring)',
        ];

        $corrOk = !($this->correlation instanceof NullQuoteWriteCorrelationRepository);
        $probeDetail = 'not wired (requires WRITE_* + quote_write_correlation_dev)';
        if ($corrOk) {
            try {
                $found = $this->correlation->findQuoteIdByPlatformOperationId(
                    '00000000-0000-4000-8000-000000000000',
                );
                $probeDetail = $found === null
                    ? 'lookup OK (no row for sentinel UUID)'
                    : 'lookup returned unexpected row';
                $corrOk = $found === null;
            } catch (\Throwable $e) {
                $corrOk = false;
                $probeDetail = 'lookup failed: ' . $e::class;
            }
        }
        $checks['CORRELATION'] = [
            'status' => $corrOk ? 'READY' : 'NOT_READY',
            'detail' => $probeDetail,
        ];

        $hasCreate = in_array(Module::PERMISSION_CREATE, $actor->permissions, true)
            || in_array(strtoupper(Module::PERMISSION_CREATE), array_map('strtoupper', $actor->permissions), true);
        $checks['QUOTE_CREATE'] = [
            'status' => $hasCreate ? 'READY' : 'NOT_READY',
            'detail' => $hasCreate ? 'actor has QUOTE.CREATE' : 'missing QUOTE.CREATE',
        ];

        $operatorId = $this->operators->resolveRaisedByOperatorId($actor, false);
        $checks['OPERATOR'] = [
            'status' => ($operatorId !== null && $operatorId > 0) ? 'READY' : 'NOT_READY',
            'detail' => ($operatorId !== null && $operatorId > 0)
                ? ('sa_op resolved (desc_id present)')
                : 'no descript.desc_type=36 mapping for actor',
        ];

        $variantPublicId = (string) ($fixture['variantPublicId'] ?? '');
        $variantCode = isset($fixture['variantCode']) ? (string) $fixture['variantCode'] : null;
        $length = isset($fixture['length']) ? (string) $fixture['length'] : null;
        $line = null;
        if ($variantPublicId !== '' || ($variantCode !== null && $variantCode !== '')) {
            $line = $this->lineMapping->resolve(
                $variantPublicId !== '' ? $variantPublicId : '00000000-0000-4000-8000-ffffffffffff',
                $variantCode,
                $length,
            );
        }
        $checks['LINE_MAPPING'] = [
            'status' => $line !== null ? 'READY' : 'NOT_READY',
            'detail' => $line !== null
                ? sprintf(
                    'cata=%s catdt=%d col=%s pm=%s',
                    $line['productCode'],
                    $line['categoryType'],
                    $line['colourId'] === null ? 'null' : (string) $line['colourId'],
                    $line['packMatrixId'] === null ? 'null' : (string) $line['packMatrixId'],
                )
                : 'mapper returned null (unsupported or missing code/length)',
        ];

        $pmIsNotMargin = $line === null
            || (int) ($line['packMatrixId'] ?? 0) !== 45;
        $checks['PM_NOT_MARGIN'] = [
            'status' => $pmIsNotMargin ? 'READY' : 'NOT_READY',
            'detail' => 'tqoti.pm must be pack-matrix id (e.g. 1501), never margin %',
        ];

        $customer = trim((string) ($fixture['customerPublicId'] ?? ''));
        $checks['CUSTOMER_FIXTURE'] = [
            'status' => $customer !== '' ? 'READY' : 'NOT_READY',
            'detail' => $customer !== '' ? 'customerPublicId supplied for fixture' : 'customerPublicId missing',
        ];

        $infrastructureBlocking = [];
        foreach (self::INFRASTRUCTURE_CHECKS as $name) {
            if (($checks[$name]['status'] ?? '') !== 'READY') {
                $infrastructureBlocking[] = $name;
            }
        }

        $domainBlocking = [];
        foreach (self::DOMAIN_CHECKS as $name) {
            if (($checks[$name]['status'] ?? '') !== 'READY') {
                $domainBlocking[] = $name;
            }
        }

        $infrastructureReady = $infrastructureBlocking === [];
        $domainReady = $domainBlocking === [];
        $writeEnabled = $this->writeConfig->enabled;
        $readyExceptWriteFlag = $infrastructureReady && $domainReady;
        $readyForControlledEnablement = $readyExceptWriteFlag && !$writeEnabled;

        $blocking = array_values(array_merge($infrastructureBlocking, $domainBlocking));
        if ($writeEnabled) {
            // Flag ON is not a blocker for "ready except flag"; tracked separately.
        }

        return [
            'infrastructureReady' => $infrastructureReady,
            'domainReady' => $domainReady,
            'writeEnabled' => $writeEnabled,
            'readyForControlledEnablement' => $readyForControlledEnablement,
            'readyExceptWriteFlag' => $readyExceptWriteFlag,
            'writeFlagEnabled' => $writeEnabled,
            'tableSuffix' => $suffix,
            'checks' => $checks,
            'blocking' => $blocking,
            'infrastructureBlocking' => $infrastructureBlocking,
            'domainBlocking' => $domainBlocking,
        ];
    }
}
