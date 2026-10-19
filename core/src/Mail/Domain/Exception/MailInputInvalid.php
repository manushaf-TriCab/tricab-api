<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Domain\Exception;

/** The request asked for something the contract does not allow. */
final class MailInputInvalid extends MailFailure
{
}
