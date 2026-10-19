<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Domain\Exception;

/** The person lacks the permission for this action. A policy refusal, not a state one: see {@see MailActionBlocked}. */
final class MailActionForbidden extends MailFailure
{
}
