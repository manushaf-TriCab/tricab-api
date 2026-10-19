<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Domain\Exception;

use RuntimeException;

/** A failure the inbox expects, with a message written to be shown to a person. */
abstract class MailFailure extends RuntimeException
{
}
