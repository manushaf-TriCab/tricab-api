<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Rfq\Storage;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Rfq\Storage\DraftActor;
use TriCab\Core\Rfq\Storage\SalesRepAttribution;

/**
 * Owner decision, 28 Sep 2026: the sales rep is whoever worked on the RFQ
 * last, in both of the source's rep fields: the assignee and the 4js author.
 */
final class SalesRepAttributionTest extends TestCase
{
    public function test_the_worker_becomes_assignee_and_author(): void
    {
        $result = SalesRepAttribution::apply(['author' => 'SCOTTH', 'region' => 'VIC'], 'scott@tricab.com', new DraftActor(' Felicity@TriCab.com ', 'sales', null), 'felicityb');

        self::assertSame('felicity@tricab.com', $result['assignee']);
        self::assertSame(['author' => 'FELICITYB', 'region' => 'VIC'], $result['quoteDetails']);
        self::assertSame(SalesRepAttribution::AUTHOR_FROM_OPERATOR, $result['authorSource']);
        self::assertSame(['from' => 'scott@tricab.com', 'to' => 'felicity@tricab.com'], $result['assigneeChange']);
    }

    public function test_an_unconfirmed_worker_never_overwrites_a_real_author(): void
    {
        $result = SalesRepAttribution::apply(['author' => 'SCOTTH'], 'scott@tricab.com', new DraftActor('new.starter@tricab.com', 'sales', null), null);

        self::assertSame('SCOTTH', $result['quoteDetails']['author']);
        self::assertSame(SalesRepAttribution::AUTHOR_KEPT, $result['authorSource']);
        self::assertSame('new.starter@tricab.com', $result['assignee'], 'the assignee still follows the worker');
    }

    public function test_a_blank_author_gets_the_source_login_fallback(): void
    {
        $result = SalesRepAttribution::apply(['author' => '  '], null, new DraftActor('manushaf@tricab.com', 'sales', null), null);

        self::assertSame('MANUSHAF', $result['quoteDetails']['author']);
        self::assertSame(SalesRepAttribution::AUTHOR_FROM_LOGIN, $result['authorSource']);
        self::assertSame(['from' => null, 'to' => 'manushaf@tricab.com'], $result['assigneeChange']);
    }

    public function test_an_external_address_is_never_turned_into_an_author(): void
    {
        $result = SalesRepAttribution::apply([], null, new DraftActor('buyer@customer.com.au', 'sales', null), null);

        self::assertArrayNotHasKey('author', $result['quoteDetails']);
        self::assertNull($result['authorSource']);
    }

    public function test_the_same_worker_again_is_no_assignee_change(): void
    {
        $result = SalesRepAttribution::apply(['author' => 'SCOTTH'], 'Scott@TriCab.com', new DraftActor('scott@tricab.com', 'sales', null), 'SCOTTH');

        self::assertNull($result['assigneeChange']);
    }

    public function test_a_write_with_nobody_behind_it_changes_neither(): void
    {
        $result = SalesRepAttribution::apply(['author' => 'SCOTTH'], 'scott@tricab.com', new DraftActor(null, null, null), 'FELICITYB');

        self::assertSame('scott@tricab.com', $result['assignee']);
        self::assertSame('SCOTTH', $result['quoteDetails']['author']);
        self::assertNull($result['authorSource']);
    }
}
