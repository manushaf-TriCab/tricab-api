<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Domain\Exception;

use RuntimeException;

/** A workflow step the rules refuse right now. Nothing was written; maps to 409 with `reason` as the code. */
final class NprWorkflowRefused extends RuntimeException
{
    public const NO_MANAGER_ROUTE = 'NPR_NO_MANAGER_ROUTE';
    public const SELF_ENDORSEMENT = 'NPR_SELF_ENDORSEMENT_NOT_ALLOWED';
    public const NOT_ALLOWED_IN_STATUS = 'NPR_ACTION_NOT_ALLOWED';
    public const SIGNING_POLICY_NOT_CONFIGURED = 'NPR_SIGNING_POLICY_NOT_CONFIGURED';
    public const ROUTE_OVERLAP = 'NPR_ROUTE_OVERLAP';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function notAllowedIn(string $status): self
    {
        return new self(self::NOT_ALLOWED_IN_STATUS, sprintf("That action is not available while the request is '%s'.", $status));
    }
}
