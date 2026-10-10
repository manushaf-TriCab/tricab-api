<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Domain;

/**
 * A refusal vendor matching wrote to be shown to a person: the source's own message, with the status
 * the platform answers (the source's 400 is a 422, as in Tri-AR). It is an {@see ApFailure}, so every Tri-AP
 * action maps it with the same `catch (ApFailure)` as the rest.
 */
abstract class ApVendorFailure extends ApFailure
{
}
