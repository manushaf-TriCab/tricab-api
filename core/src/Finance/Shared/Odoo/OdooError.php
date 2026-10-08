<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Odoo;

use RuntimeException;

/**
 * Odoo could not be read: not configured, unreachable, or it answered with an error. The
 * message is safe to store and show: it names the model, the method and the status, and
 * never the API key.
 */
class OdooError extends RuntimeException
{
}
