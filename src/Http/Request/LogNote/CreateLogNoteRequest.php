<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\LogNote;

use TriCab\Api\Http\Request\Admin\RequestFields;
use TriCab\Core\LogNote\Domain\ValueObject\LogNoteInteractionType;

final readonly class CreateLogNoteRequest
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function fromArray(array $payload): array
    {
        $customerPublicId = RequestFields::stringOrNull($payload, 'customerPublicId')
            ?? RequestFields::stringOrNull($payload, 'customerId');
        if ($customerPublicId === null) {
            RequestFields::requireString($payload, 'customerPublicId');
        }

        $normalized = $payload;
        $normalized['customerPublicId'] = $customerPublicId;
        $normalized['interactionType'] = RequestFields::stringOrDefault(
            $payload,
            'interactionType',
            LogNoteInteractionType::DEFAULT,
        );
        $normalized['category'] = RequestFields::requireString($payload, 'category');
        $normalized['body'] = RequestFields::requireString($payload, 'body');
        $normalized['contactPublicId'] = RequestFields::stringOrNull($payload, 'contactPublicId');
        $normalized['contactName'] = RequestFields::stringOrNull($payload, 'contactName');
        $normalized['contactPosition'] = RequestFields::stringOrNull($payload, 'contactPosition');
        $normalized['followUpAt'] = RequestFields::stringOrNull($payload, 'followUpAt');
        $normalized['callResultCode'] = RequestFields::stringOrNull($payload, 'callResultCode');
        $normalized['isAiGenerated'] = RequestFields::boolOrDefault($payload, 'isAiGenerated', false);

        return $normalized;
    }
}
