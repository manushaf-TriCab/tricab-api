<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Npr;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Npr\Application\Service\NprAccessPolicy;
use TriCab\Core\Npr\Application\Service\TsdNprRequestReader;
use TriCab\Core\Npr\Domain\RequestStatus;
use TriCab\Core\Npr\Infrastructure\InMemoryNprRequestRepository;
use TriCab\Core\Npr\Module;
use TriCab\Core\Npr\NprRequestReader;

/** The reader CableFit uses: TSD.READ holders, requests with TSD or later, nothing else. */
final class TsdNprRequestReaderTest extends TestCase
{
    private const WITH_TSD = '00000000-0000-4000-8000-000000000001';
    private const DRAFT = '00000000-0000-4000-8000-000000000002';

    private function reader(): NprRequestReader
    {
        $repository = (new InMemoryNprRequestRepository())
            ->withRequest(NprFixtures::record(
                self::WITH_TSD,
                10,
                RequestStatus::WithTsd,
                2,
                ['customer_name' => ['state' => 'provided', 'value' => 'BHP'], 'armour' => ['state' => 'provided', 'value' => ['none']]],
                NprFixtures::validVariants(),
            ))
            ->withRequest(NprFixtures::record(self::DRAFT, 10, RequestStatus::Draft));

        return new TsdNprRequestReader($repository, new NprAccessPolicy(), NprFixtures::validator());
    }

    public function test_a_tsd_reader_gets_the_snapshot(): void
    {
        $snapshot = $this->reader()->findForTsd(NprFixtures::tsd(), self::WITH_TSD);

        self::assertNotNull($snapshot);
        self::assertSame(self::WITH_TSD, $snapshot->publicId);
        self::assertSame('NPR-2026-0001', $snapshot->requestNumber);
        self::assertSame(2, $snapshot->currentRevision);
        self::assertSame('Mining trailing cable', $snapshot->title);
        self::assertSame('BHP', $snapshot->customerName);
        self::assertSame('var_1', $snapshot->variants[0]['id']);
    }

    public function test_section_5_answers_are_only_those_the_dictionary_puts_in_section_5(): void
    {
        $snapshot = $this->reader()->findForTsd(NprFixtures::tsd(), self::WITH_TSD);

        self::assertNotNull($snapshot);
        self::assertSame(['armour'], array_keys($snapshot->section5Answers));
    }

    public function test_a_request_not_yet_with_tsd_is_null(): void
    {
        self::assertNull($this->reader()->findForTsd(NprFixtures::tsd(), self::DRAFT));
    }

    public function test_a_returned_request_is_visible_only_if_tsd_had_received_it(): void
    {
        $repository = (new InMemoryNprRequestRepository())
            ->withRequest(NprFixtures::record('00000000-0000-4000-8000-0000000000b1', 10, RequestStatus::ReturnedToSales, 2, [], [], new \DateTimeImmutable('2026-10-03')))
            ->withRequest(NprFixtures::record('00000000-0000-4000-8000-0000000000b2', 10, RequestStatus::ReturnedToSales));
        $reader = new TsdNprRequestReader($repository, new NprAccessPolicy(), NprFixtures::validator());

        self::assertNotNull($reader->findForTsd(NprFixtures::tsd(), '00000000-0000-4000-8000-0000000000b1'));
        self::assertNull($reader->findForTsd(NprFixtures::tsd(), '00000000-0000-4000-8000-0000000000b2'));
    }

    public function test_a_missing_request_is_null(): void
    {
        self::assertNull($this->reader()->findForTsd(NprFixtures::tsd(), '00000000-0000-4000-8000-0000000000ff'));
    }

    public function test_without_tsd_read_it_is_null_even_for_the_owner(): void
    {
        self::assertNull($this->reader()->findForTsd(NprFixtures::requester(), self::WITH_TSD));
        self::assertNull($this->reader()->findForTsd(NprFixtures::user(30, [Module::PERMISSION_TSD_WORK, 'TSD.ACCESS']), self::WITH_TSD));
    }

    public function test_many_returns_only_the_readable_requests_keyed_by_public_id(): void
    {
        $many = $this->reader()->findManyForTsd(NprFixtures::tsd(), [self::WITH_TSD, self::DRAFT, '00000000-0000-4000-8000-0000000000ff', self::WITH_TSD]);

        self::assertSame([self::WITH_TSD], array_keys($many));
        self::assertSame('NPR-2026-0001', $many[self::WITH_TSD]->requestNumber);
        self::assertSame([], $this->reader()->findManyForTsd(NprFixtures::requester(), [self::WITH_TSD]));
    }

    public function test_the_container_binds_the_reader(): void
    {
        $app = \TriCab\Api\Tests\TestAppFactory::create();
        $container = $app->getContainer();

        self::assertInstanceOf(TsdNprRequestReader::class, $container->get(NprRequestReader::class));
    }
}
