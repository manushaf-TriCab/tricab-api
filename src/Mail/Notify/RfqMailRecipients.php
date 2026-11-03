<?php

declare(strict_types=1);

namespace TriCab\Api\Mail\Notify;

/** Who is told: active people who can work RFQs (RFQ_SALES and RFQ_ADMIN hold `RFQ.DRAFT.CREATE`). */
interface RfqMailRecipients
{
    /** @return list<int> platform user ids */
    public function userIds(): array;
}
