<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\QuoteOrder;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TriCab\Core\QuoteOrder\Application\Command\PersistQuoteDraft;
use TriCab\Core\QuoteOrder\Application\Command\PersistQuoteDraftExtendedLine;
use TriCab\Core\QuoteOrder\Application\Command\PersistQuoteDraftLine;
use TriCab\Core\QuoteOrder\Application\Exception\QuoteWriteCurrencyUnknownException;
use TriCab\Core\QuoteOrder\Application\Exception\QuoteWriteDisabledException;
use TriCab\Core\QuoteOrder\Application\Exception\QuoteWriteFailedException;
use TriCab\Core\QuoteOrder\Application\Exception\QuoteWriteInvalidCommandException;
use TriCab\Core\QuoteOrder\Application\Exception\QuoteWriteMisconfiguredException;
use TriCab\Core\QuoteOrder\Application\Service\QuoteWriteDryRunService;
use TriCab\Core\QuoteOrder\Domain\Mapping\LegacyQuoteCurrencyMapper;
use TriCab\Core\QuoteOrder\Domain\Mapping\LegacyQuoteStatusMapper;
use TriCab\Core\QuoteOrder\Domain\Mapping\PersistQuoteDraftMapper;
use TriCab\Core\QuoteOrder\Infrastructure\DisabledQuoteWriteRepository;
use TriCab\Core\QuoteOrder\Infrastructure\InMemoryLegacyQuoteWriteConnection;
use TriCab\Core\QuoteOrder\Infrastructure\InMemoryQuoteWriteCorrelationRepository;
use TriCab\Core\QuoteOrder\Infrastructure\RecordingQuoteWriteAuditLogger;
use TriCab\Core\QuoteOrder\Infrastructure\TransactionalLegacyQuoteWriteRepository;
use TriCab\Core\QuoteOrder\Live\LegacyQuoteWriteConfig;
use TriCab\Core\QuoteOrder\Live\NonProductionQuoteWriteSafety;
use TriCab\Core\Product\Migration\Pricing\LegacyCyrteMappingContract;

final class QuoteWriteInfrastructureTest extends TestCase
{
    public function test_writeFlagDefaultsDisabled(): void
    {
        $config = LegacyQuoteWriteConfig::fromEnvironment([]);
        self::assertFalse($config->enabled);
        self::assertFalse($config->isConfigured());
    }

    public function test_writeFlagFalseIsDisabled(): void
    {
        $config = LegacyQuoteWriteConfig::fromEnvironment([
            'QUOTES_ORDERS_LEGACY_WRITE_ENABLED' => 'false',
        ]);
        self::assertFalse($config->enabled);
    }

    public function test_enabledWithoutCredentialsFailsClosed(): void
    {
        $this->expectException(QuoteWriteMisconfiguredException::class);
        LegacyQuoteWriteConfig::fromEnvironment([
            'QUOTES_ORDERS_LEGACY_WRITE_ENABLED' => 'true',
            'QUOTES_ORDERS_LEGACY_WRITE_TABLE_SUFFIX' => '_dev',
        ]);
    }

    public function test_enabledWithoutTableSuffixFailsClosed(): void
    {
        $this->expectException(QuoteWriteMisconfiguredException::class);
        LegacyQuoteWriteConfig::fromEnvironment([
            'QUOTES_ORDERS_LEGACY_WRITE_ENABLED' => 'true',
            'QUOTES_ORDERS_LEGACY_WRITE_DSN' => 'mysql:host=127.0.0.1;dbname=tc',
            'QUOTES_ORDERS_LEGACY_WRITE_USER' => 'writer',
            'QUOTES_ORDERS_LEGACY_WRITE_PASSWORD' => 'secret',
        ]);
    }

    public function test_roCredentialsAreNotUsedAsWriteCredentials(): void
    {
        $config = LegacyQuoteWriteConfig::fromEnvironment([
            'QUOTES_ORDERS_LEGACY_WRITE_ENABLED' => 'false',
            'LEGACY_TC_DSN' => 'mysql:host=ro;dbname=tc',
            'LEGACY_TC_USER' => 'readonly',
            'LEGACY_TC_PASSWORD' => 'ro-secret',
        ]);
        self::assertNull($config->dsn);
        self::assertNull($config->user);
        self::assertNull($config->password);
    }

    public function test_disabledRepositoryThrowsBeforeSql(): void
    {
        $repo = new DisabledQuoteWriteRepository();
        $this->expectException(QuoteWriteDisabledException::class);
        $repo->persistDraft($this->sampleDraft());
    }

    public function test_transactionalRepositoryRespectsDisabledFlag(): void
    {
        $connection = new InMemoryLegacyQuoteWriteConnection();
        $repo = new TransactionalLegacyQuoteWriteRepository(
            new LegacyQuoteWriteConfig(false, null, null, null, null, '_dev', true),
            $connection,
            new InMemoryQuoteWriteCorrelationRepository(),
        );
        try {
            $repo->persistDraft($this->sampleDraft());
            self::fail('Expected QuoteWriteDisabledException');
        } catch (QuoteWriteDisabledException) {
            self::assertSame([], $connection->committedOperations());
            self::assertSame([], $connection->pendingOperations());
        }
    }

    public function test_successfulPersistCommitsHeaderLinesExtendedAndTotal(): void
    {
        $connection = new InMemoryLegacyQuoteWriteConnection();
        $audit = new RecordingQuoteWriteAuditLogger();
        $repo = $this->enabledRepo($connection, $audit);

        $result = $repo->persistDraft($this->sampleDraft(withExtended: true));

        self::assertSame('100000001', $result->quoteId);
        self::assertSame('1705', $result->statusCode);
        self::assertSame('250.00', $result->totalNet);
        self::assertCount(2, $result->lineIds);
        self::assertSame(4, $connection->committedInsertCount()); // header + 2 lines + 1 ext (correlation is in-memory repo)
        $ops = $connection->committedOperations();
        self::assertCount(5, $ops); // + total UPDATE
        self::assertStringContainsString('UPDATE', $ops[4]['sql']);
        self::assertSame('250.00', $ops[4]['params']['tnet']);
        self::assertTrue($audit->events()[0]['success']);
    }

    public function test_emptySuffixRejectedByNonProductionSafety(): void
    {
        $this->expectException(QuoteWriteMisconfiguredException::class);
        (new NonProductionQuoteWriteSafety())->assertDevTableSuffixRequired(
            new LegacyQuoteWriteConfig(
                enabled: true,
                dsn: 'mysql:host=test;dbname=tc',
                user: 'writer',
                password: 'x',
                sslMode: null,
                tableSuffix: '',
                tableSuffixConfigured: true,
            ),
        );
    }

    public function test_platformOperationIdRequiredBeforePersist(): void
    {
        $connection = new InMemoryLegacyQuoteWriteConnection();
        $repo = $this->enabledRepo($connection);
        $draft = $this->sampleDraft();
        $draft = new PersistQuoteDraft(
            documentType: $draft->documentType,
            statusCode: $draft->statusCode,
            createdAt: $draft->createdAt,
            validUntil: $draft->validUntil,
            companyCode: $draft->companyCode,
            officeId: $draft->officeId,
            raisedByOperatorId: $draft->raisedByOperatorId,
            metricType: $draft->metricType,
            customerId: $draft->customerId,
            paymentTermsId: $draft->paymentTermsId,
            currencyCode: $draft->currencyCode,
            reference: $draft->reference,
            customerGenerated: $draft->customerGenerated,
            lines: $draft->lines,
            recipientContactId: $draft->recipientContactId,
            platformOperationId: null,
        );
        $this->expectException(QuoteWriteFailedException::class);
        $repo->persistDraft($draft);
    }

    public function test_lineNumbersAreDeterministicFromCommandOrder(): void
    {
        $connection = new InMemoryLegacyQuoteWriteConnection();
        $repo = $this->enabledRepo($connection);
        $repo->persistDraft($this->sampleDraft());
        $ops = $connection->committedOperations();
        self::assertSame(1, $ops[1]['params']['itm']);
        self::assertSame(2, $ops[2]['params']['itm']);
    }

    public function test_headerFailureRollsBack(): void
    {
        $connection = new InMemoryLegacyQuoteWriteConnection();
        $connection->failWhenSqlContains('tqoth');
        $repo = $this->enabledRepo($connection);

        try {
            $repo->persistDraft($this->sampleDraft());
            self::fail('Expected failure');
        } catch (QuoteWriteFailedException) {
            self::assertSame([], $connection->committedOperations());
            self::assertFalse($connection->inTransaction());
        }
    }

    public function test_lineFailureRollsBackHeader(): void
    {
        $connection = new InMemoryLegacyQuoteWriteConnection();
        $connection->failWhenSqlContains('tqoti');
        $repo = $this->enabledRepo($connection);

        try {
            $repo->persistDraft($this->sampleDraft());
            self::fail('Expected failure');
        } catch (QuoteWriteFailedException) {
            self::assertSame([], $connection->committedOperations());
        }
    }

    public function test_secondLineFailureRollsBackAll(): void
    {
        $inner = new InMemoryLegacyQuoteWriteConnection();
        $connection = new CountingFailLegacyQuoteWriteConnection($inner, failOnLineInsertNumber: 2);
        $repo = new TransactionalLegacyQuoteWriteRepository(
            new LegacyQuoteWriteConfig(
                enabled: true,
                dsn: 'mysql:host=test;dbname=tc',
                user: 'writer',
                password: 'x',
                sslMode: null,
                tableSuffix: '_dev',
                tableSuffixConfigured: true,
            ),
            $connection,
            new InMemoryQuoteWriteCorrelationRepository(),
            new PersistQuoteDraftMapper(),
            new RecordingQuoteWriteAuditLogger(),
        );

        try {
            $repo->persistDraft($this->sampleDraft());
            self::fail('Expected failure');
        } catch (QuoteWriteFailedException) {
            self::assertSame([], $inner->committedOperations());
        }
    }

    public function test_extendedFailureRollsBack(): void
    {
        $connection = new InMemoryLegacyQuoteWriteConnection();
        $connection->failWhenSqlContains('tqote');
        $repo = $this->enabledRepo($connection);

        try {
            $repo->persistDraft($this->sampleDraft(withExtended: true));
            self::fail('Expected failure');
        } catch (QuoteWriteFailedException) {
            self::assertSame([], $connection->committedOperations());
        }
    }

    public function test_totalUpdateFailureRollsBack(): void
    {
        $connection = new InMemoryLegacyQuoteWriteConnection();
        $connection->failWhenSqlContains('UPDATE');
        $repo = $this->enabledRepo($connection);

        try {
            $repo->persistDraft($this->sampleDraft());
            self::fail('Expected failure');
        } catch (QuoteWriteFailedException) {
            self::assertSame([], $connection->committedOperations());
        }
    }

    public function test_commitFailureReportsSafely(): void
    {
        $connection = new InMemoryLegacyQuoteWriteConnection();
        $connection->failOnCommit();
        $repo = $this->enabledRepo($connection);

        try {
            $repo->persistDraft($this->sampleDraft());
            self::fail('Expected failure');
        } catch (QuoteWriteFailedException $e) {
            self::assertStringContainsString('commit', strtolower($e->getMessage()));
            self::assertSame([], $connection->committedOperations());
        }
    }

    public function test_unknownCurrencyFailsBeforeSql(): void
    {
        $connection = new InMemoryLegacyQuoteWriteConnection();
        $repo = $this->enabledRepo($connection);
        $draft = $this->sampleDraft(currency: 'EUR');

        try {
            $repo->persistDraft($draft);
            self::fail('Expected currency exception');
        } catch (QuoteWriteCurrencyUnknownException) {
            self::assertSame([], $connection->committedOperations());
            self::assertSame([], $connection->pendingOperations());
        }
    }

    public function test_invalidCommandFailsBeforeSql(): void
    {
        $connection = new InMemoryLegacyQuoteWriteConnection();
        $repo = $this->enabledRepo($connection);
        $draft = new PersistQuoteDraft(
            documentType: 1611,
            statusCode: '1705',
            createdAt: new DateTimeImmutable('now', new DateTimeZone('Australia/Melbourne')),
            validUntil: new DateTimeImmutable('+2 days', new DateTimeZone('Australia/Melbourne')),
            companyCode: 2507,
            officeId: 7,
            raisedByOperatorId: 42,
            metricType: 1301,
            customerId: 99,
            paymentTermsId: 3303,
            currencyCode: 'AUD',
            reference: 'REF',
            customerGenerated: false,
            lines: [],
            platformOperationId: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        );

        try {
            $repo->persistDraft($draft);
            self::fail('Expected invalid command');
        } catch (QuoteWriteInvalidCommandException) {
            self::assertSame([], $connection->committedOperations());
        }
    }

    public function test_currencyMapperSupportsAudNzdUsd(): void
    {
        $mapper = new LegacyQuoteCurrencyMapper();
        self::assertSame(LegacyCyrteMappingContract::AUD, $mapper->legacyIdForCode('AUD'));
        self::assertSame(LegacyCyrteMappingContract::NZD, $mapper->legacyIdForCode('nzd'));
        self::assertSame(LegacyCyrteMappingContract::USD, $mapper->legacyIdForCode('USD'));
        self::assertSame('AUD', $mapper->codeForLegacyId(LegacyCyrteMappingContract::AUD));
    }

    public function test_statusMapperDraftAndFinal(): void
    {
        $mapper = new LegacyQuoteStatusMapper();
        self::assertSame('1705', $mapper->draftStatusCode(false));
        self::assertSame('1710', $mapper->draftStatusCode(true));
        self::assertSame('1703', $mapper->finalStatusCode(false));
        self::assertSame('1711', $mapper->finalStatusCode(true));
        $mapper->assertDraftStatus('1705');
        $this->expectException(QuoteWriteInvalidCommandException::class);
        $mapper->assertDraftStatus('1703');
    }

    public function test_dryRunMapsWithoutSql(): void
    {
        $preview = (new QuoteWriteDryRunService())->preview($this->sampleDraft(withExtended: true));
        self::assertSame(1611, $preview['header']['dt']);
        self::assertSame(3200, $preview['header']['cy']);
        self::assertSame('250.00', $preview['totalNet']);
        self::assertCount(2, $preview['lines']);
        self::assertCount(1, $preview['lines'][0]['extended']);
        self::assertSame('PROVEN', $preview['classification']['dt']);
        self::assertSame('DERIVED', $preview['classification']['cus']);
    }

    public function test_mapperUsesMelbourneDateAndTime(): void
    {
        $mapper = new PersistQuoteDraftMapper();
        $created = new DateTimeImmutable('2026-09-17 16:30:00', new DateTimeZone('UTC'));
        self::assertSame('2026-09-18', $mapper->formatCreatedDate($created)); // AEST UTC+10 in September
        self::assertSame(230, $mapper->formatCreatedTime($created));
    }

    public function test_tbcZeroTotalIsRepresentable(): void
    {
        $draft = $this->sampleDraft(lineNet: '0.00', secondLineNet: '0.00');
        self::assertSame('0.00', $draft->derivedTotalNet());
        $preview = (new QuoteWriteDryRunService())->preview($draft);
        self::assertSame('0.00', $preview['totalNet']);
    }

    public function test_publicConfigNeverExposesPassword(): void
    {
        $config = LegacyQuoteWriteConfig::fromEnvironment([
            'QUOTES_ORDERS_LEGACY_WRITE_ENABLED' => 'true',
            'QUOTES_ORDERS_LEGACY_WRITE_DSN' => 'mysql:host=127.0.0.1;dbname=tc',
            'QUOTES_ORDERS_LEGACY_WRITE_USER' => 'writer',
            'QUOTES_ORDERS_LEGACY_WRITE_PASSWORD' => 'super-secret',
            'QUOTES_ORDERS_LEGACY_WRITE_TABLE_SUFFIX' => '_dev',
        ]);
        $public = $config->toPublicArray();
        self::assertArrayNotHasKey('password', $public);
        self::assertArrayNotHasKey('dsn', $public);
        self::assertArrayNotHasKey('user', $public);
        self::assertTrue($public['enabled']);
        self::assertSame('tc.tqoth_dev', $public['headerTable']);
    }

    private function enabledRepo(
        InMemoryLegacyQuoteWriteConnection $connection,
        ?RecordingQuoteWriteAuditLogger $audit = null,
    ): TransactionalLegacyQuoteWriteRepository {
        return new TransactionalLegacyQuoteWriteRepository(
            new LegacyQuoteWriteConfig(
                enabled: true,
                dsn: 'mysql:host=test;dbname=tc',
                user: 'writer',
                password: 'x',
                sslMode: null,
                tableSuffix: '_dev',
                tableSuffixConfigured: true,
            ),
            $connection,
            new InMemoryQuoteWriteCorrelationRepository(),
            new PersistQuoteDraftMapper(),
            $audit ?? new RecordingQuoteWriteAuditLogger(),
        );
    }

    private function sampleDraft(
        bool $withExtended = false,
        string $currency = 'AUD',
        string $lineNet = '100.00',
        string $secondLineNet = '150.00',
    ): PersistQuoteDraft {
        $ext = $withExtended
            ? [new PersistQuoteDraftExtendedLine(unitsPerPack: '500', packCount: '2', comments: 'run A')]
            : [];

        return new PersistQuoteDraft(
            documentType: 1611,
            statusCode: '1705',
            createdAt: new DateTimeImmutable('2026-09-18 10:00:00', new DateTimeZone('Australia/Melbourne')),
            validUntil: new DateTimeImmutable('2026-09-20', new DateTimeZone('Australia/Melbourne')),
            companyCode: 2507,
            officeId: 7,
            raisedByOperatorId: 42,
            metricType: 1301,
            customerId: 12345,
            paymentTermsId: 3303,
            currencyCode: $currency,
            reference: 'PO-TEST',
            customerGenerated: false,
            lines: [
                new PersistQuoteDraftLine(
                    productCode: 'ABC123',
                    categoryType: 1615,
                    packCount: '2',
                    unitsOrLength: '1000',
                    sellUnitPrice: '0.10',
                    lineNet: $lineNet,
                    costUnitPrice: '0.05',
                    costValue: '50.00',
                    colourId: 1,
                    packMatrixId: 1501,
                    reference: 'L1',
                    extended: $ext,
                ),
                new PersistQuoteDraftLine(
                    productCode: 'DEF456',
                    categoryType: 1660,
                    packCount: '1',
                    unitsOrLength: '1',
                    sellUnitPrice: $secondLineNet,
                    lineNet: $secondLineNet,
                    costUnitPrice: '75.00',
                    costValue: '75.00',
                    packMatrixId: 1501,
                ),
            ],
            recipientContactId: 99,
            platformOperationId: 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
        );
    }
}

/**
 * Test double: fail on the Nth tqoti insert while delegating to an in-memory connection.
 */
final class CountingFailLegacyQuoteWriteConnection implements \TriCab\Core\QuoteOrder\Contract\LegacyQuoteWriteConnection
{
    private int $lineInserts = 0;

    public function __construct(
        private readonly InMemoryLegacyQuoteWriteConnection $inner,
        private readonly int $failOnLineInsertNumber,
    ) {
    }

    public function beginTransaction(): void
    {
        $this->inner->beginTransaction();
    }

    public function commit(): void
    {
        $this->inner->commit();
    }

    public function rollBack(): void
    {
        $this->inner->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->inner->inTransaction();
    }

    public function execute(string $sql, array $params = []): int
    {
        if (str_contains(strtolower($sql), 'tqoti')) {
            ++$this->lineInserts;
            if ($this->lineInserts === $this->failOnLineInsertNumber) {
                throw new \RuntimeException('Simulated line N failure');
            }
        }

        return $this->inner->execute($sql, $params);
    }

    public function query(string $sql, array $params = []): array
    {
        return $this->inner->query($sql, $params);
    }

    public function lastInsertId(): string
    {
        return $this->inner->lastInsertId();
    }
}
