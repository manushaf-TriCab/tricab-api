<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Domain;

/** The candidate does not exist, or its NPR request is not readable by the user (indistinguishable). */
final class CandidateNotFound extends CableFitException
{
}
