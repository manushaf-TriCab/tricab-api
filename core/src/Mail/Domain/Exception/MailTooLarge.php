<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Domain\Exception;

/** An attachment is over the size the RFQ parser itself accepts, so it is not fetched. */
final class MailTooLarge extends MailFailure
{
}
