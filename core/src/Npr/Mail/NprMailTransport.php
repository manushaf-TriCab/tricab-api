<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Mail;

/** Sends one message. Throws on any failure; the message names the status or code, never a secret. */
interface NprMailTransport
{
    /** @throws NprMailFailed */
    public function send(NprMailMessage $message): void;
}
