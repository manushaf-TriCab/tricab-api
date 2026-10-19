<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Mail;

use TriCab\Api\Mail\Persistence\InMemoryMailStore;
use TriCab\Core\Mail\Contract\MailStore;

final class InMemoryMailStoreTest extends MailStoreBehaviour
{
    protected function newStore(): MailStore
    {
        return new InMemoryMailStore();
    }
}
