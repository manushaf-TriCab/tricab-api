<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Mail\Notify;

use DateTimeImmutable;
use TriCab\Api\Mail\Notify\RfqMailNotification;
use TriCab\Api\Mail\Notify\RfqMailNotificationStore;
use TriCab\Api\Tests\Mail\MailAppTestCase;
use TriCab\Core\Rfq\Module;

final class RfqMailNotificationsRoutesTest extends MailAppTestCase
{
    private function seed(): void
    {
        $store = $this->container()->get(RfqMailNotificationStore::class);
        self::assertInstanceOf(RfqMailNotificationStore::class, $store);
        $store->add(new RfqMailNotification('n1', 1, 'draft-1', 'New email RFQ: x', 'From y.', '/admin/quotegen/draft-1', new DateTimeImmutable('2026-11-03 01:00:00 UTC'), false));
    }

    public function test_with_the_flag_off_the_list_is_empty(): void
    {
        $this->boot([Module::PERMISSION_DRAFT_CREATE]);
        $this->seed();

        $data = self::at(json_decode((string) $this->call('GET', '/api/v1/rfqs/mail-notifications')->getBody(), true), 'data');
        self::assertSame(['unread' => 0, 'items' => []], $data);
    }

    public function test_with_the_flag_on_the_list_shows_the_callers_notices_and_read_clears_the_count(): void
    {
        $this->boot([Module::PERMISSION_DRAFT_CREATE], ['RFQ_MAIL_NOTIFY_ENABLED' => 'true']);
        $this->seed();

        $data = self::at(json_decode((string) $this->call('GET', '/api/v1/rfqs/mail-notifications')->getBody(), true), 'data');
        self::assertSame(1, self::at($data, 'unread'));
        self::assertSame('/admin/quotegen/draft-1', self::at($data, 'items', 0, 'link'));

        self::assertSame(200, $this->call('POST', '/api/v1/rfqs/mail-notifications/read')->getStatusCode());
        $after = self::at(json_decode((string) $this->call('GET', '/api/v1/rfqs/mail-notifications')->getBody(), true), 'data');
        self::assertSame(0, self::at($after, 'unread'));
    }
}
