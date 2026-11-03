<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Hr;

use Psr\Http\Message\ResponseInterface;
use TriCab\Api\Hr\OnboardingLink\OnboardingLinkRefused;
use TriCab\Api\Http\Response\JsonResponder;

/** The refusal-to-response mapping the onboarding link actions share. */
trait OnboardingLinkActionSupport
{
    private function refused(JsonResponder $responder, OnboardingLinkRefused $refusal): ResponseInterface
    {
        return $responder->error($refusal->errorCode, $refusal->getMessage(), $refusal->status);
    }
}
