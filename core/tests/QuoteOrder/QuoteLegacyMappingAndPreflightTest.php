<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\QuoteOrder;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection;
use TriCab\Core\QuoteOrder\Application\Command\PersistQuoteDraft;
use TriCab\Core\QuoteOrder\Application\Command\PersistQuoteDraftLine;
use TriCab\Core\QuoteOrder\Application\Service\QuoteWriteDryRunService;
use TriCab\Core\QuoteOrder\Application\Service\QuoteWritePreflightService;
use TriCab\Core\QuoteOrder\Domain\Mapping\LegacyQuoteLineTypeDefaults;
use TriCab\Core\QuoteOrder\Domain\Mapping\PersistQuoteDraftMapper;
use TriCab\Core\QuoteOrder\Infrastructure\DescriptStaffQuoteLegacyOperatorIdResolver;
use TriCab\Core\QuoteOrder\Infrastructure\InMemoryQuoteWriteCorrelationRepository;
use TriCab\Core\QuoteOrder\Infrastructure\InMemoryQuoteWriteIdempotencyStore;
use TriCab\Core\QuoteOrder\Infrastructure\NullQuoteWriteCorrelationRepository;
use TriCab\Core\QuoteOrder\Infrastructure\NullQuoteWriteIdempotencyStore;
use TriCab\Core\QuoteOrder\Infrastructure\SimpleCableQuoteLineLegacyMappingResolver;
use TriCab\Core\QuoteOrder\Live\LegacyQuoteWriteConfig;
use TriCab\Core\QuoteOrder\Module;
use DateTimeImmutable;
use DateTimeZone;

final class QuoteLegacyMappingAndPreflightTest extends TestCase
{
    public function test_simpleCableMapperSetsProvenCatdtColPm(): void
    {
        $mapper = new SimpleCableQuoteLineLegacyMappingResolver();
        $resolved = $mapper->resolve(
            'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
            'XL-PAXN/1C25OR',
            '100',
        );
        self::assertNotNull($resolved);
        self::assertSame('XL-PAXN/1C25OR', $resolved['productCode']);
        self::assertSame(1615, $resolved['categoryType']);
        self::assertSame(4008, $resolved['colourId']);
        self::assertSame(1501, $resolved['packMatrixId']);
    }

    public function test_marginPercentDoesNotBecomePm(): void
    {
        $mapper = new SimpleCableQuoteLineLegacyMappingResolver();
        $resolved = $mapper->resolve('v', 'XL-PAXN/1C25OR', '50');
        self::assertNotNull($resolved);
        self::assertSame(1501, $resolved['packMatrixId']);
        self::assertNotSame(45, $resolved['packMatrixId']);
        self::assertNotSame(50, $resolved['packMatrixId']);

        $draft = new PersistQuoteDraft(
            documentType: 1611,
            statusCode: '1705',
            createdAt: new DateTimeImmutable('2026-09-18 10:00:00', new DateTimeZone('Australia/Melbourne')),
            validUntil: new DateTimeImmutable('2026-09-20', new DateTimeZone('Australia/Melbourne')),
            companyCode: 2507,
            officeId: 7,
            raisedByOperatorId: 42,
            metricType: 1301,
            customerId: 1,
            paymentTermsId: 3303,
            currencyCode: 'AUD',
            reference: 'MARGIN-PM',
            customerGenerated: false,
            lines: [
                new PersistQuoteDraftLine(
                    productCode: 'XL-PAXN/1C25OR',
                    categoryType: 1615,
                    packCount: '1',
                    unitsOrLength: '100',
                    sellUnitPrice: '2.00',
                    lineNet: '200.00',
                    costUnitPrice: '1.00',
                    costValue: '100.00',
                    colourId: 4008,
                    packMatrixId: 1501,
                ),
            ],
            platformOperationId: 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
        );
        $preview = (new PersistQuoteDraftMapper())->map($draft);
        self::assertSame(1501, $preview['lines'][0]['line']['pm']);
        self::assertNotSame(45, $preview['lines'][0]['line']['pm']);
    }

    public function test_simpleCableQtyAndQotqtyAreTotalUnits(): void
    {
        $draft = new PersistQuoteDraft(
            documentType: 1611,
            statusCode: '1705',
            createdAt: new DateTimeImmutable('2026-09-18 10:00:00', new DateTimeZone('Australia/Melbourne')),
            validUntil: new DateTimeImmutable('2026-09-20', new DateTimeZone('Australia/Melbourne')),
            companyCode: 2507,
            officeId: 7,
            raisedByOperatorId: 42,
            metricType: 1301,
            customerId: 1,
            paymentTermsId: 3303,
            currencyCode: 'AUD',
            reference: 'QTY',
            customerGenerated: false,
            lines: [
                new PersistQuoteDraftLine(
                    productCode: 'XL-PAXN/1C25OR',
                    categoryType: 1615,
                    packCount: '2',
                    unitsOrLength: '500',
                    sellUnitPrice: '1.00',
                    lineNet: '1000.00',
                    costUnitPrice: '0.50',
                    costValue: '500.00',
                    colourId: 4008,
                    packMatrixId: 1501,
                ),
            ],
            platformOperationId: 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
        );
        $line = (new PersistQuoteDraftMapper())->map($draft)['lines'][0]['line'];
        self::assertSame('1000.0000', $line['qty']);
        self::assertSame('1000.0000', $line['qotqty']);
    }

    public function test_accessoryCodeRejectedEvenWithLength(): void
    {
        $mapper = new SimpleCableQuoteLineLegacyMappingResolver();
        self::assertNull($mapper->resolve('v', 'G08-AAXS/1.2D38', '1'));
    }

    public function test_missingLengthUnsupportedForFirstWrite(): void
    {
        $mapper = new SimpleCableQuoteLineLegacyMappingResolver();
        self::assertNull($mapper->resolve('v', 'XL-PAXN/1C25OR', null));
    }

    public function test_operatorResolverUsesDescriptType36(): void
    {
        $seenSql = null;
        $legacy = new class($seenSql) implements LegacyReadOnlyConnection {
            public function __construct(private ?string &$seenSql)
            {
            }

            public function query(
                string $sql,
                array $params = [],
                string $repository = 'unknown',
                string $method = 'unknown',
            ): array {
                $this->seenSql = $sql;

                return [['desc_id' => 1023235]];
            }

            public function sourceLabel(): string
            {
                return 'test';
            }
        };

        $resolver = new DescriptStaffQuoteLegacyOperatorIdResolver($legacy);
        $actor = new AuthenticatedUser(
            userId: 1,
            publicId: '99999999-9999-9999-9999-999999999999',
            emailAddress: 'thilanh@tricab.com',
            displayName: 'Thilan',
            status: 'ACTIVE',
            roles: ['ADMINISTRATOR'],
            permissions: [Module::PERMISSION_CREATE],
        );
        self::assertSame(1023235, $resolver->resolveRaisedByOperatorId($actor, false));
        self::assertNull($resolver->resolveRaisedByOperatorId($actor, true));
        self::assertNotNull($seenSql);
        self::assertStringContainsString('desc_type', (string) $seenSql);
        self::assertSame(36, LegacyQuoteLineTypeDefaults::STAFF_DESCRIPT_TYPE);
    }

    public function test_operatorAmbiguousOrMissingReturnsNull(): void
    {
        $legacy = new class implements LegacyReadOnlyConnection {
            public function query(
                string $sql,
                array $params = [],
                string $repository = 'unknown',
                string $method = 'unknown',
            ): array {
                return [];
            }

            public function sourceLabel(): string
            {
                return 'test';
            }
        };
        $resolver = new DescriptStaffQuoteLegacyOperatorIdResolver($legacy);
        $actor = new AuthenticatedUser(
            userId: 1,
            publicId: '99999999-9999-9999-9999-999999999999',
            emailAddress: 'nobody@tricab.com',
            displayName: 'Nobody',
            status: 'ACTIVE',
            roles: ['ADMINISTRATOR'],
            permissions: [Module::PERMISSION_CREATE],
        );
        self::assertNull($resolver->resolveRaisedByOperatorId($actor, false));
    }

    public function test_preflightReportsWriteFlagOffAndBlocking(): void
    {
        $service = new QuoteWritePreflightService(
            writeConfig: LegacyQuoteWriteConfig::fromEnvironment([]),
            idempotency: new NullQuoteWriteIdempotencyStore(),
            correlation: new NullQuoteWriteCorrelationRepository(),
            operators: new class implements \TriCab\Core\QuoteOrder\Contract\QuoteLegacyOperatorIdResolver {
                public function resolveRaisedByOperatorId(AuthenticatedUser $actor, bool $customerGenerated): ?int
                {
                    return null;
                }
            },
            lineMapping: new SimpleCableQuoteLineLegacyMappingResolver(),
        );
        $actor = new AuthenticatedUser(
            userId: 1,
            publicId: '99999999-9999-9999-9999-999999999999',
            emailAddress: 'thilanh@tricab.com',
            displayName: 'Thilan',
            status: 'ACTIVE',
            roles: ['ADMINISTRATOR'],
            permissions: [Module::PERMISSION_CREATE, 'CUSTOMER.PRICE_AS'],
        );
        $result = $service->run($actor, [
            'variantPublicId' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
            'variantCode' => 'XL-PAXN/1C25OR',
            'length' => '100',
            'customerPublicId' => '11111111-1111-1111-1111-111111111111',
        ]);
        self::assertFalse($result['writeFlagEnabled']);
        self::assertFalse($result['writeEnabled']);
        self::assertFalse($result['infrastructureReady']);
        self::assertFalse($result['domainReady']);
        self::assertFalse($result['readyForControlledEnablement']);
        self::assertSame('OFF_INTENTIONALLY', $result['checks']['WRITE_FLAG']['status']);
        self::assertSame('READY', $result['checks']['LINE_MAPPING']['status']);
        self::assertSame('READY', $result['checks']['PM_NOT_MARGIN']['status']);
        self::assertContains('WRITE_CREDENTIALS', $result['blocking']);
        self::assertContains('OPERATOR', $result['blocking']);
        self::assertContains('WRITE_CREDENTIALS', $result['infrastructureBlocking']);
        self::assertContains('OPERATOR', $result['domainBlocking']);
    }

    public function test_preflightReadyForControlledEnablementWhenDepsPresentAndFlagOff(): void
    {
        $correlation = new InMemoryQuoteWriteCorrelationRepository();
        $service = new QuoteWritePreflightService(
            writeConfig: new LegacyQuoteWriteConfig(
                enabled: false,
                dsn: 'mysql:host=test;dbname=tc',
                user: 'writer',
                password: 'x',
                sslMode: null,
                tableSuffix: '_dev',
                tableSuffixConfigured: true,
            ),
            idempotency: new InMemoryQuoteWriteIdempotencyStore(),
            correlation: $correlation,
            operators: new class implements \TriCab\Core\QuoteOrder\Contract\QuoteLegacyOperatorIdResolver {
                public function resolveRaisedByOperatorId(AuthenticatedUser $actor, bool $customerGenerated): ?int
                {
                    return 1023235;
                }
            },
            lineMapping: new SimpleCableQuoteLineLegacyMappingResolver(),
        );
        $actor = new AuthenticatedUser(
            userId: 1,
            publicId: '99999999-9999-9999-9999-999999999999',
            emailAddress: 'thilanh@tricab.com',
            displayName: 'Thilan',
            status: 'ACTIVE',
            roles: ['ADMINISTRATOR'],
            permissions: [Module::PERMISSION_CREATE],
        );
        $result = $service->run($actor, [
            'variantCode' => 'XL-PAXN/1C25OR',
            'length' => '100',
            'customerPublicId' => '11111111-1111-1111-1111-111111111111',
        ]);
        self::assertTrue($result['infrastructureReady']);
        self::assertTrue($result['domainReady']);
        self::assertFalse($result['writeEnabled']);
        self::assertTrue($result['readyForControlledEnablement']);
        self::assertTrue($result['readyExceptWriteFlag']);
        self::assertSame([], $result['blocking']);
        self::assertSame('OFF_INTENTIONALLY', $result['checks']['WRITE_FLAG']['status']);
    }

    public function test_preflightFlagOnDoesNotCountAsControlledEnablementReady(): void
    {
        $correlation = new InMemoryQuoteWriteCorrelationRepository();
        $service = new QuoteWritePreflightService(
            writeConfig: new LegacyQuoteWriteConfig(
                enabled: true,
                dsn: 'mysql:host=test;dbname=tc',
                user: 'writer',
                password: 'x',
                sslMode: null,
                tableSuffix: '_dev',
                tableSuffixConfigured: true,
            ),
            idempotency: new InMemoryQuoteWriteIdempotencyStore(),
            correlation: $correlation,
            operators: new class implements \TriCab\Core\QuoteOrder\Contract\QuoteLegacyOperatorIdResolver {
                public function resolveRaisedByOperatorId(AuthenticatedUser $actor, bool $customerGenerated): ?int
                {
                    return 1023235;
                }
            },
            lineMapping: new SimpleCableQuoteLineLegacyMappingResolver(),
        );
        $actor = new AuthenticatedUser(
            userId: 1,
            publicId: '99999999-9999-9999-9999-999999999999',
            emailAddress: 'mapped@tricab.com',
            displayName: 'Mapped',
            status: 'ACTIVE',
            roles: ['ADMINISTRATOR'],
            permissions: [Module::PERMISSION_CREATE],
        );
        $result = $service->run($actor, [
            'variantCode' => 'XL-PAXN/1C25OR',
            'length' => '100',
            'customerPublicId' => '11111111-1111-1111-1111-111111111111',
        ]);
        self::assertTrue($result['infrastructureReady']);
        self::assertTrue($result['domainReady']);
        self::assertTrue($result['writeEnabled']);
        self::assertFalse($result['readyForControlledEnablement']);
    }

    public function test_defaultsCatalogue(): void
    {
        self::assertSame(1615, LegacyQuoteLineTypeDefaults::CABLE_CATEGORY_TYPE);
        self::assertSame(1501, LegacyQuoteLineTypeDefaults::CABLE_PACK_MATRIX_ID);
        self::assertSame(4008, LegacyQuoteLineTypeDefaults::LEGACY_CREATE_COLOUR_ID);
        self::assertNotSame(
            LegacyQuoteLineTypeDefaults::CABLE_PACK_MATRIX_ID,
            45,
        );
    }
}
