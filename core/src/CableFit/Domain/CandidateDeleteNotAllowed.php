<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Domain;

/** Only the design's creator, or a platform Administrator, may delete it. */
final class CandidateDeleteNotAllowed extends CableFitException
{
}
