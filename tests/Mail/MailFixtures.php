<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Mail;

use DateTimeImmutable;
use TriCab\Core\Mail\Application\DTO\MailParty;
use TriCab\Core\Mail\Application\DTO\NewAssessment;
use TriCab\Core\Mail\Application\DTO\NewAttachment;
use TriCab\Core\Mail\Application\DTO\NewMessage;
use TriCab\Core\Mail\Application\DTO\RecordedMessage;
use TriCab\Core\Mail\Application\Service\MailIntakeRecorder;

/** Mail the way the reader and the classifier would record it. */
final class MailFixtures
{
    public const MAILBOX = 'melbourne@tricab.com';

    public static function at(string $time = '2026-10-18 09:00:00'): DateTimeImmutable
    {
        return new DateTimeImmutable($time . ' UTC');
    }

    /** @param list<NewAttachment> $attachments */
    public static function message(
        string $key,
        string $conversation = '',
        string $subject = 'Quote for 100m of 3C 2.5mm',
        string $at = '2026-10-18 09:00:00',
        string $fromName = 'Jo Customer',
        string $fromAddress = 'jo@customer.example',
        array $attachments = [],
        bool $isRead = false,
        string $preview = 'Hi, could you quote us for the following cable',
    ): NewMessage {
        return new NewMessage(
            self::MAILBOX,
            'graph-' . $key,
            '<' . $key . '@customer.example>',
            $conversation === '' ? 'conv-' . $key : $conversation,
            $subject,
            new MailParty($fromName, $fromAddress),
            [new MailParty('TriCab Melbourne', self::MAILBOX)],
            [],
            self::at($at),
            $preview,
            $isRead,
            $attachments,
            'TriCab Melbourne',
        );
    }

    /**
     * Records a message and its assessment in one go.
     *
     * @param list<NewAttachment> $attachments
     */
    public static function classified(
        MailIntakeRecorder $recorder,
        string $key,
        string $label,
        float $confidence = 0.9,
        string $conversation = '',
        string $at = '2026-10-18 09:00:00',
        array $attachments = [],
        bool $injection = false,
        bool $isRead = false,
        string $subject = 'Quote for 100m of 3C 2.5mm',
        string $fromName = 'Jo Customer',
        string $fromAddress = 'jo@customer.example',
    ): RecordedMessage {
        $recorded = $recorder->recordMessage(self::message($key, $conversation, $subject, $at, $fromName, $fromAddress, $attachments, $isRead));
        $recorder->recordAssessment(
            self::MAILBOX,
            '<' . $key . '@customer.example>',
            NewAssessment::classified($label, $confidence, 'Asks for a price on named cable.', 'claude-test', 'rfq-intake-test', self::at($at)->modify('+45 seconds'), 800, $injection),
        );

        return $recorded;
    }

    public static function skipped(MailIntakeRecorder $recorder, string $key, string $at = '2026-10-18 09:00:00'): RecordedMessage
    {
        $recorded = $recorder->recordMessage(self::message($key, '', 'Automatic reply: out of office', $at, 'Auto', 'noreply@customer.example'));
        $recorder->recordAssessment(self::MAILBOX, '<' . $key . '@customer.example>', NewAssessment::skipped('Auto-reply', 'rfq-intake-test', self::at($at)->modify('+1 second')));

        return $recorded;
    }
}
