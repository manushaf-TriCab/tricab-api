<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Mail;

/** NPR_MAIL_MODE: off (suppressed, never sent), test (everything to one test address) or live. */
enum NprMailMode: string
{
    case Off = 'off';
    case Test = 'test';
    case Live = 'live';
}
