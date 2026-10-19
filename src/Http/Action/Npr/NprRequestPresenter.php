<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Npr;

use TriCab\Core\Npr\Application\Service\NprRequestView;
use TriCab\Core\Npr\Domain\NprJson;

/**
 * The JSON shape of an NPR. camelCase, the public id and never a numeric one.
 * `version` is the concurrency token a save must send back.
 */
final class NprRequestPresenter
{
    private const TIME = 'Y-m-d\TH:i:s\Z';

    /**
     * The whole request, with its answers and variants.
     *
     * @return array<string, mixed>
     */
    public static function detail(NprRequestView $view): array
    {
        $request = $view->request;

        return self::summary($view) + [
            'answers' => NprJson::answers($request->answers),
            'variants' => NprJson::variants($request->variants),
        ];
    }

    /**
     * The header only, for lists.
     *
     * @return array<string, mixed>
     */
    public static function summary(NprRequestView $view): array
    {
        $request = $view->request;

        return [
            'publicId' => $request->publicId,
            'requestNumber' => $request->requestNumber,
            'revision' => $request->revision,
            'version' => $request->version,
            'status' => $request->status->value,
            'screeningOutcome' => $request->screeningOutcome,
            'title' => $request->title,
            'customerName' => $request->customerName,
            'urgencyLevel' => $request->urgencyLevel,
            'requester' => [
                'name' => $request->requesterName,
                'email' => $request->requesterEmail,
                'branch' => $request->requesterBranch,
            ],
            'createdAt' => $request->createdAt->format(self::TIME),
            'updatedAt' => $request->updatedAt->format(self::TIME),
            'submittedAt' => $request->submittedAt?->format(self::TIME),
            'allowedActions' => $view->allowedActions,
        ];
    }
}
