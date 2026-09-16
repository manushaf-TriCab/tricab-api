<?php

declare(strict_types=1);

namespace TriCab\Core\LogNote\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Core\Customer\Application\Exception\CustomerContactNotFound;
use TriCab\Core\Customer\Application\Exception\CustomerNotFound;
use TriCab\Core\Customer\Contract\CustomerContactRepository;
use TriCab\Core\Customer\Contract\CustomerRepository;
use TriCab\Core\Customer\Domain\Exception\InvalidCustomerValue;
use TriCab\Core\Customer\Domain\ValueObject\CustomerContactPublicId;
use TriCab\Core\Customer\Domain\ValueObject\CustomerPublicId;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\LogNote\Application\DTO\LogNoteDetails;
use TriCab\Core\LogNote\Application\Exception\LogNoteClientIdentityRejected;
use TriCab\Core\LogNote\Application\Exception\LogNoteContactCustomerMismatch;
use TriCab\Core\LogNote\Contract\LogNoteAuditRecorder;
use TriCab\Core\LogNote\Contract\LogNoteRepository;
use TriCab\Core\LogNote\Domain\Entity\LogNote;
use TriCab\Core\LogNote\Domain\ValueObject\LogNoteBody;
use TriCab\Core\LogNote\Domain\ValueObject\LogNoteCategory;
use TriCab\Core\LogNote\Domain\ValueObject\LogNoteInteractionType;
use TriCab\Core\LogNote\Domain\ValueObject\LogNotePublicId;
use TriCab\Core\Shared\Time\Clock;

final class CreateLogNoteService
{
    private const FORBIDDEN_IDENTITY_FIELDS = [
        'createdBy',
        'createdByUserId',
        'createdByUserPublicId',
        'salesrep',
        'salesRep',
        'userId',
        'login',
        'author',
        'actorUserId',
        'actor',
    ];

    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly CustomerContactRepository $contacts,
        private readonly LogNoteRepository $notes,
        private readonly LogNoteAuditRecorder $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function execute(ActorContext $actor, array $payload): LogNoteDetails
    {
        $this->rejectClientIdentity($payload);

        $rawCustomer = $payload['customerPublicId'] ?? $payload['customerId'] ?? '';
        $customerPublicId = CustomerPublicId::fromString(is_string($rawCustomer) ? $rawCustomer : '');
        $customer = $this->customers->findDetailsByPublicId($customerPublicId);
        if ($customer === null) {
            throw CustomerNotFound::forPublicId($customerPublicId->toString());
        }

        $customerId = $this->customers->findIdByPublicId($customerPublicId);
        if ($customerId === null) {
            throw CustomerNotFound::forPublicId($customerPublicId->toString());
        }

        $contactId = null;
        $contactName = isset($payload['contactName']) && is_string($payload['contactName'])
            ? trim($payload['contactName'])
            : null;
        $contactPosition = isset($payload['contactPosition']) && is_string($payload['contactPosition'])
            ? trim($payload['contactPosition'])
            : null;
        $contactPublicIdRaw = isset($payload['contactPublicId']) && is_string($payload['contactPublicId'])
            ? trim($payload['contactPublicId'])
            : '';

        if ($contactPublicIdRaw !== '') {
            $contactPublicId = CustomerContactPublicId::fromString($contactPublicIdRaw);
            $contact = $this->contacts->findDetailsByPublicId($contactPublicId);
            if ($contact === null) {
                throw CustomerContactNotFound::forPublicId($contactPublicId->toString());
            }
            try {
                $contactCustomerPublicId = CustomerPublicId::fromString($contact->customerPublicId);
            } catch (InvalidCustomerValue) {
                throw LogNoteContactCustomerMismatch::forContact($contactPublicId->toString());
            }
            if (!$contactCustomerPublicId->equals($customerPublicId)) {
                throw LogNoteContactCustomerMismatch::forContact($contactPublicId->toString());
            }
            $contactId = $this->contacts->findIdByPublicId($contactPublicId);
            $contactName = $contact->displayName;
            if ($contactPosition === null || $contactPosition === '') {
                $contactPosition = $contact->position;
            }
        }

        $interactionType = LogNoteInteractionType::fromString(
            isset($payload['interactionType']) && is_string($payload['interactionType']) && trim($payload['interactionType']) !== ''
                ? (string) $payload['interactionType']
                : LogNoteInteractionType::DEFAULT,
        );
        $category = LogNoteCategory::fromString(
            isset($payload['category']) && is_string($payload['category']) ? (string) $payload['category'] : '',
        );
        $body = LogNoteBody::fromString(isset($payload['body']) && is_string($payload['body']) ? (string) $payload['body'] : '');

        $callResultCode = isset($payload['callResultCode']) && is_string($payload['callResultCode'])
            ? trim($payload['callResultCode'])
            : null;
        $followUpAt = $this->parseFollowUp($payload['followUpAt'] ?? null);
        $isAiGenerated = $payload['isAiGenerated'] ?? false;
        if (!is_bool($isAiGenerated)) {
            $isAiGenerated = false;
        }

        $note = LogNote::create(
            publicId: LogNotePublicId::generate(),
            customerId: $customerId,
            customerPublicId: $customerPublicId,
            customerContactId: $contactId,
            contactNameSnapshot: $contactName,
            contactPositionSnapshot: $contactPosition,
            interactionType: $interactionType,
            category: $category,
            body: $body,
            callResultCode: $callResultCode !== '' ? $callResultCode : null,
            followUpAt: $followUpAt,
            isAiGenerated: $isAiGenerated,
            createdByUserId: UserId::fromInt($actor->userId),
            createdAtUtc: $this->clock->now(),
        );

        $saved = $this->notes->save($note);
        $this->audit->recordCreated(
            UserId::fromInt($actor->userId),
            LogNotePublicId::fromString($saved->publicId),
            $customerPublicId,
        );

        // 4JS sync intentionally disabled pending explicit integration approval.
        // CustomerNoteExternalSync is registered but must not be invoked in Phase 1.

        return $saved;
    }

    /** @param array<string, mixed> $payload */
    private function rejectClientIdentity(array $payload): void
    {
        foreach (self::FORBIDDEN_IDENTITY_FIELDS as $field) {
            if (array_key_exists($field, $payload)) {
                throw LogNoteClientIdentityRejected::forbiddenField($field);
            }
        }
    }

    private function parseFollowUp(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
