<?php

declare(strict_types=1);

namespace TriCab\Core\Analytics\Domain;

enum ActivityPlatform: string
{
    case Web = 'web';
    case Mobile = 'mobile';
}
