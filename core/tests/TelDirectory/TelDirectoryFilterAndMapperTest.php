<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\TelDirectory;

use PHPUnit\Framework\TestCase;
use TriCab\Core\TelDirectory\Application\Service\TelDirectoryFilter;
use TriCab\Core\TelDirectory\Application\Service\TelDirectoryMapper;

final class TelDirectoryFilterAndMapperTest extends TestCase
{
    public function test_excludes_disabled_users(): void
    {
        $filter = new TelDirectoryFilter();
        self::assertFalse($filter->accepts([
            'accountEnabled' => false,
            'mail' => 'ok@tricab.com',
            'jobTitle' => 'Engineer',
        ]));
    }

    public function test_excludes_invalid_domains(): void
    {
        $filter = new TelDirectoryFilter();
        self::assertFalse($filter->accepts([
            'accountEnabled' => true,
            'mail' => 'person@gmail.com',
            'jobTitle' => 'Engineer',
        ]));
    }

    public function test_excludes_empty_job_title(): void
    {
        $filter = new TelDirectoryFilter();
        self::assertFalse($filter->accepts([
            'accountEnabled' => true,
            'mail' => 'ok@tricab.com',
            'jobTitle' => '',
        ]));
    }

    public function test_excludes_hard_excluded_emails(): void
    {
        $filter = new TelDirectoryFilter();
        foreach (TelDirectoryFilter::HARD_EXCLUDED_EMAILS as $email) {
            self::assertFalse($filter->accepts([
                'accountEnabled' => true,
                'mail' => $email,
                'jobTitle' => 'Staff',
            ]), $email);
        }
    }

    public function test_allows_known_domains(): void
    {
        $filter = new TelDirectoryFilter();
        foreach (['a@tricab.com', 'b@sparkywarehouse.com.au', 'c@tricabtrading.com'] as $email) {
            self::assertTrue($filter->accepts([
                'accountEnabled' => true,
                'mail' => $email,
                'jobTitle' => 'Role',
            ]), $email);
        }
    }

    public function test_maps_dto_and_strips_telephone_extension(): void
    {
        $mapper = new TelDirectoryMapper();
        $entry = $mapper->map([
            'accountEnabled' => true,
            'displayName' => 'Jane Doe',
            'jobTitle' => 'Engineer',
            'mail' => 'jane@tricab.com',
            'mobilePhone' => '0400 111 222',
            'businessPhones' => ['03 9123 4567x89'],
            'officeLocation' => 'Melbourne',
        ]);

        self::assertNotNull($entry);
        $payload = $entry->toArray();
        self::assertSame('Jane Doe', $payload['name']);
        self::assertSame('Engineer', $payload['jobTitle']);
        self::assertSame('jane@tricab.com', $payload['email']);
        self::assertSame('0400 111 222', $payload['mobile']);
        self::assertSame('03 9123 4567', $payload['telephone']);
        self::assertSame('Melbourne', $payload['location']);
        self::assertArrayNotHasKey('mobileSecondary', $payload);
    }

    public function test_null_phone_and_location_fields(): void
    {
        $mapper = new TelDirectoryMapper();
        $entry = $mapper->map([
            'accountEnabled' => true,
            'displayName' => 'No Phone',
            'jobTitle' => 'Clerk',
            'mail' => 'nophone@tricab.com',
            'mobilePhone' => null,
            'businessPhones' => [],
            'officeLocation' => '',
        ]);

        self::assertNotNull($entry);
        $payload = $entry->toArray();
        self::assertNull($payload['mobile']);
        self::assertNull($payload['telephone']);
        self::assertNull($payload['location']);
    }

    public function test_ag_tricab_dual_mobile_legacy_rule(): void
    {
        $mapper = new TelDirectoryMapper();
        $entry = $mapper->map([
            'accountEnabled' => true,
            'displayName' => 'AG',
            'jobTitle' => 'Director',
            'mail' => 'ag@tricab.com',
            'mobilePhone' => '0400 000 001',
            'businessPhones' => ['03 9999 8888x12'],
            'officeLocation' => 'HQ',
        ]);

        self::assertNotNull($entry);
        $payload = $entry->toArray();
        self::assertNull($payload['telephone']);
        self::assertSame('0400 000 001', $payload['mobile']);
        self::assertSame('03 9999 8888x12', $payload['mobileSecondary']);
        self::assertSame('HQ', $payload['location']);
    }
}
