<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Domain;

/** The NPR request does not exist or the user may not read it (indistinguishable). */
final class NprRequestNotAccessible extends CableFitException
{
}
