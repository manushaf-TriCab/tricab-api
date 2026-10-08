<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Migration;

use RuntimeException;

/** The id translation service could not be used at all (not "this id has no translation"). */
final class ArMessageIdTranslationFailed extends RuntimeException
{
}
