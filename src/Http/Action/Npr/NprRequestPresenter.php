<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Npr;

use TriCab\Core\Npr\Application\Service\NprRequestView;
use TriCab\Core\Npr\Domain\ManagerRoute;
use TriCab\Core\Npr\Domain\NprAttachment;
use TriCab\Core\Npr\Domain\NprJson;
use TriCab\Core\Npr\Domain\NprPerson;

/**
 * The JSON shape of an NPR. camelCase, the public id and never a numeric one.
 * `version` is the concurrency token a save must send back.
 */
final class NprRequestPresenter
{
    private const TIME = 'Y-m-d\TH:i:s\Z';

    /**
     * The whole request, with its answers, variants and attachments.
     *
     * @param list<NprAttachment> $attachments
     * @return array<string, mixed>
     */
    public static function detail(NprRequestView $view, array $attachments = []): array
    {
        $request = $view->request;

        return self::summary($view) + [
            'answers' => NprJson::answers($request->answers),
            'variants' => NprJson::variants($request->variants),
            'attachments' => array_map(self::attachment(...), $attachments),
            'screening' => [
                'outcome' => $request->screeningOutcome,
                'rationale' => $request->screeningRationale,
                'conditions' => $request->screeningConditions,
                'decidedAt' => $request->screeningDecidedAt?->format(self::TIME),
            ],
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
            'endorsedAt' => $request->endorsedAt?->format(self::TIME),
            'tsdReceivedAt' => $request->tsdReceivedAt?->format(self::TIME),
            'tsdAcknowledgedAt' => $request->tsdAcknowledgedAt?->format(self::TIME),
            'manager' => self::person($request->manager),
            'tsdOwner' => self::person($request->tsdOwner),
            'screeningSignatory' => self::person($request->signatory),
            'allowedActions' => $view->allowedActions,
        ];
    }

    /** @return array<string, mixed> */
    public static function attachment(NprAttachment $file): array
    {
        return [
            'id' => $file->publicId,
            'filename' => $file->filename,
            'title' => $file->title,
            'documentType' => $file->documentType,
            'mimeType' => $file->mimeType,
            'sizeBytes' => $file->sizeBytes,
            'uploadedBy' => ['userId' => $file->uploadedBy->userId, 'name' => $file->uploadedBy->name, 'email' => $file->uploadedBy->email],
            'uploadedAt' => $file->uploadedAt->format(self::TIME),
        ];
    }

    /** @return array{userId: int, name: string, email: string}|null */
    public static function person(?NprPerson $person): ?array
    {
        return $person === null ? null : ['userId' => $person->userId, 'name' => $person->name, 'email' => $person->email];
    }

    /** @return array<string, mixed> */
    public static function route(ManagerRoute $route, \DateTimeImmutable $today): array
    {
        return [
            'id' => $route->id,
            'branch' => $route->branch,
            'manager' => self::person($route->manager),
            'isDelegate' => $route->isDelegate,
            'validFrom' => $route->validFrom->format('Y-m-d'),
            'validTo' => $route->validTo?->format('Y-m-d'),
            'active' => $route->isActiveOn($today),
        ];
    }
}
