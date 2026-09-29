<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Rfq\Application;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Rfq\Application\DTO\RfqCustomerOption;
use TriCab\Core\Rfq\Application\DTO\RfqPayTerm;
use TriCab\Core\Rfq\Application\Service\RfqLookupService;
use TriCab\Core\Rfq\Contract\RfqCustomerDirectory;
use TriCab\Core\Tests\Rfq\Fixtures\RecordingCustomerDirectory;
use TriCab\Core\Rfq\Contract\RfqPayTermsDirectory;

final class RfqLookupServiceTest extends TestCase
{
    private RecordingCustomerDirectory $directory;

    protected function setUp(): void
    {
        $this->directory = new RecordingCustomerDirectory();
    }

    private function customers(): RfqCustomerDirectory
    {
        return $this->directory;
    }

    /** @param list<string> $codes */
    private static function payTerms(array $codes): RfqPayTermsDirectory
    {
        return new class ($codes) implements RfqPayTermsDirectory {
            /** @param list<string> $codes */
            public function __construct(private readonly array $codes)
            {
            }

            public function all(): array
            {
                return array_map(static fn (string $code): RfqPayTerm => new RfqPayTerm($code, $code . ' terms'), $this->codes);
            }
        };
    }

    public function test_an_empty_search_finds_nothing_without_asking(): void
    {
        $service = new RfqLookupService($this->customers(), self::payTerms([]));

        self::assertSame([], $service->searchCustomers('   '));
        self::assertSame([], $service->contacts(0));
        self::assertSame([], $this->directory->asked);
    }

    public function test_searches_are_trimmed_and_limited_as_in_the_source(): void
    {
        $service = new RfqLookupService($this->customers(), self::payTerms([]));

        $service->searchCustomers('  a&h ');
        $service->contacts(37193);

        self::assertSame([['a&h', 10], ['37193', 50]], $this->directory->asked);
    }

    public function test_a_long_search_is_cut_short(): void
    {
        $service = new RfqLookupService($this->customers(), self::payTerms([]));

        $service->searchCustomers(str_repeat('x', 500));

        self::assertSame(RfqLookupService::MAX_QUERY_LENGTH, strlen($this->directory->asked[0][0]));
    }

    public function test_the_dropdown_starts_on_the_default_code_as_4js_spells_it(): void
    {
        $service = new RfqLookupService($this->customers(), self::payTerms(['100PP', 'EOM030ND']), 'eom030nd');

        $answer = $service->payTerms();

        self::assertCount(2, $answer['terms']);
        self::assertSame('EOM030ND', $answer['defaultCode']);
    }

    public function test_a_default_4js_does_not_list_preselects_nothing(): void
    {
        $service = new RfqLookupService($this->customers(), self::payTerms(['100PP']), 'EOM030ND');

        self::assertNull($service->payTerms()['defaultCode']);
    }
}
