<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Mail;

/** One message as handed to the transport. `to` may be empty when only Cc recipients resolved. */
final readonly class NprMailMessage
{
    /**
     * @param list<string> $to
     * @param list<string> $cc
     */
    public function __construct(
        public string $subject,
        public string $html,
        public string $text,
        public array $to,
        public array $cc,
    ) {
    }
}
