<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Mail;

use TriCab\Api\Tests\Payload;
use TriCab\Core\Mail\Application\DTO\NewAttachment;
use TriCab\Core\Mail\Domain\MailVocabulary;

/**
 * The response contract is `tricab-web/src/api/mail/types.ts`. Every route's `data` must match its
 * interfaces field for field. The names are pinned here (read from the live responses, in order), and,
 * where the web repository sits beside this one, checked against that file itself so neither side can
 * drift unseen.
 */
final class MailContractShapeTest extends MailAppTestCase
{
    /** @var array<string, list<string>> interface => its fields, in the order types.ts declares them */
    private const SHAPES = [
        'MailMe' => ['canReview', 'canExport', 'mailboxes'],
        'MailMailbox' => ['id', 'address', 'displayName', 'connection', 'lastSyncAt'],
        'MailParty' => ['name', 'address'],
        'MailAssessment' => ['label', 'confidence', 'reason', 'model', 'latencyMs', 'version', 'assessedAt', 'skipped', 'injectionSuspected'],
        'MailLabelDecision' => ['kind', 'label', 'decidedBy', 'decidedAt', 'note'],
        'MailThreadSummary' => ['id', 'mailboxId', 'subject', 'from', 'preview', 'latestAt', 'messageCount', 'hasAttachments', 'unread', 'label', 'confidence', 'effectiveLabel', 'decided', 'skipped', 'reviewState'],
        'MailAttachment' => ['id', 'messageId', 'fileName', 'mimeType', 'sizeBytes'],
        'MailMessage' => ['id', 'internetMessageId', 'from', 'to', 'cc', 'receivedAt', 'preview', 'attachments'],
        'MailBlocker' => ['kind', 'message'],
        'MailActivityEvent' => ['id', 'at', 'actor', 'kind', 'description'],
        'MailLink' => ['kind', 'messageId', 'publicId', 'reference', 'title', 'href', 'createdAt'],
        'MailThreadDetail' => ['summary', 'messages', 'assessment', 'decision', 'activity', 'links', 'allowedActions', 'blockers'],
        'MailMessageBody' => ['messageId', 'html', 'remoteImagesBlocked', 'quotedHtml'],
        'MailThreadPage' => ['items', 'nextCursor', 'total'],
        'MailAccuracy' => ['range', 'total', 'labelled', 'unlabelled', 'skippedLabelled', 'humanUncertain', 'matrix', 'rfqRecall', 'rfqPrecision', 'missedRfqRate', 'uncertainShare', 'uncertainShareAll'],
        'MailAccuracyMetric' => ['count', 'of', 'rate', 'target', 'met'],
    ];

    /** @return array<string, array<string, mixed>> a thread with everything on it, and the responses for it */
    private function responses(): array
    {
        $this->boot(self::reviewer());
        $recorded = MailFixtures::classified(
            $this->recorder,
            'shape',
            'uncertain',
            0.42,
            attachments: [new NewAttachment('a1', 'list.xlsx', 'application/vnd.ms-excel', 2048)],
        );
        $this->recorder->recordSync('melbourne@tricab.com', 'connected', MailFixtures::at('2026-10-18 09:30:00'), 'TriCab Melbourne');
        $this->json('POST', '/api/v1/mail/threads/' . $recorded->threadId . '/review', ['action' => 'confirm_label', 'note' => 'right']);
        $detail = $this->data('/api/v1/mail/threads/' . $recorded->threadId);
        $this->container()->set(
            \TriCab\Core\Mail\Contract\MailBodyFetcher::class,
            new \TriCab\Core\Mail\Infrastructure\MailboxBodyFetcher($this->readerWithBody()),
        );

        return [
            'me' => $this->data('/api/v1/mail/me'),
            'page' => $this->data('/api/v1/mail/threads'),
            'detail' => $detail,
            'body' => $this->data('/api/v1/mail/messages/' . $recorded->messageId . '/body'),
            'queues' => $this->data('/api/v1/mail/queues'),
            'accuracy' => $this->data('/api/v1/mail/accuracy'),
        ];
    }

    private function readerWithBody(): \TriCab\Api\Shared\Mailbox\InMemoryMailboxReader
    {
        $reader = new \TriCab\Api\Shared\Mailbox\InMemoryMailboxReader();
        $reader->add(
            'melbourne@tricab.com',
            new \TriCab\Core\Shared\Mailbox\MailboxMessage(
                new \TriCab\Core\Shared\Mailbox\MailboxMessageSummary('graph-shape', 'x', 'jo@customer.example', 'Jo', MailFixtures::at(), true, 'p'),
                \TriCab\Core\Shared\Mailbox\MailboxMessage::BODY_HTML,
                '<p>Hello</p>',
            ),
        );

        return $reader;
    }

    /** @param list<string> $fields */
    private static function assertShape(mixed $value, array $fields, string $what): void
    {
        self::assertIsArray($value, $what);
        self::assertSame($fields, array_keys($value), $what);
    }

    public function test_every_response_has_exactly_the_contracts_fields(): void
    {
        $r = $this->responses();

        self::assertShape($r['me'], self::SHAPES['MailMe'], 'MailMe');
        self::assertShape(self::at($r, 'me', 'mailboxes', 0), self::SHAPES['MailMailbox'], 'MailMailbox');

        self::assertShape($r['page'], self::SHAPES['MailThreadPage'], 'MailThreadPage');
        self::assertShape(self::at($r, 'page', 'items', 0), self::SHAPES['MailThreadSummary'], 'MailThreadSummary');
        self::assertShape(self::at($r, 'page', 'items', 0, 'from'), self::SHAPES['MailParty'], 'MailParty');

        self::assertShape($r['detail'], self::SHAPES['MailThreadDetail'], 'MailThreadDetail');
        self::assertShape(self::at($r, 'detail', 'summary'), self::SHAPES['MailThreadSummary'], 'detail summary');
        self::assertShape(self::at($r, 'detail', 'assessment'), self::SHAPES['MailAssessment'], 'MailAssessment');
        self::assertShape(self::at($r, 'detail', 'decision'), self::SHAPES['MailLabelDecision'], 'MailLabelDecision');
        self::assertShape(self::at($r, 'detail', 'decision', 'decidedBy'), self::SHAPES['MailParty'], 'decidedBy');
        self::assertShape(self::at($r, 'detail', 'messages', 0), self::SHAPES['MailMessage'], 'MailMessage');
        self::assertShape(self::at($r, 'detail', 'messages', 0, 'from'), self::SHAPES['MailParty'], 'message from');
        self::assertShape(self::at($r, 'detail', 'messages', 0, 'to', 0), self::SHAPES['MailParty'], 'message to');
        self::assertShape(self::at($r, 'detail', 'messages', 0, 'attachments', 0), self::SHAPES['MailAttachment'], 'MailAttachment');
        self::assertShape(self::at($r, 'detail', 'activity', 0), self::SHAPES['MailActivityEvent'], 'MailActivityEvent');
        self::assertShape(self::at($r, 'detail', 'activity', 2, 'actor'), self::SHAPES['MailParty'], 'a person as actor');

        self::assertShape($r['body'], self::SHAPES['MailMessageBody'], 'MailMessageBody');
        self::assertShape($r['accuracy'], self::SHAPES['MailAccuracy'], 'MailAccuracy');
        self::assertShape(self::at($r, 'accuracy', 'rfqRecall'), self::SHAPES['MailAccuracyMetric'], 'MailAccuracyMetric');
        self::assertSame(['from', 'to'], array_keys((array) self::at($r, 'accuracy', 'range')));
        self::assertSame(MailVocabulary::LABELS, array_keys((array) self::at($r, 'accuracy', 'matrix')));
        self::assertSame(MailVocabulary::QUEUES, array_keys($r['queues']));
        self::assertShape(self::at($r, 'queues', 'all'), ['total', 'unread'], 'queue count');
    }

    public function test_values_have_the_contracts_types(): void
    {
        $r = $this->responses();

        self::assertIsString(self::at($r, 'detail', 'summary', 'id'));
        self::assertIsInt(self::at($r, 'detail', 'summary', 'messageCount'));
        self::assertIsBool(self::at($r, 'detail', 'summary', 'unread'));
        self::assertSame(0.42, self::at($r, 'detail', 'summary', 'confidence'));
        self::assertContains(self::at($r, 'detail', 'summary', 'label'), MailVocabulary::LABELS);
        self::assertContains(self::at($r, 'detail', 'summary', 'reviewState'), MailVocabulary::REVIEW_STATES);
        self::assertSame(800, self::at($r, 'detail', 'assessment', 'latencyMs'));
        self::assertIsBool(self::at($r, 'detail', 'assessment', 'skipped'));
        self::assertIsBool(self::at($r, 'detail', 'assessment', 'injectionSuspected'));
        // ISO 8601 UTC, with a Z.
        foreach ([
            self::at($r, 'detail', 'summary', 'latestAt'),
            self::at($r, 'detail', 'assessment', 'assessedAt'),
            self::at($r, 'detail', 'messages', 0, 'receivedAt'),
            self::at($r, 'detail', 'decision', 'decidedAt'),
            self::at($r, 'me', 'mailboxes', 0, 'lastSyncAt'),
        ] as $at) {
            self::assertIsString($at);
            self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(\.\d+)?Z$/', $at);
        }
        self::assertSame('confirm', self::at($r, 'detail', 'decision', 'kind'));
        self::assertSame('right', self::at($r, 'detail', 'decision', 'note'));
        self::assertSame('system', self::at($r, 'detail', 'activity', 0, 'actor'));
        self::assertContains('create_draft', Payload::list(self::at($r, 'detail', 'allowedActions')));
        self::assertSame([], $r['detail']['blockers'], 'nothing is blocked once the label is marked; the wire form is pinned below');
    }

    public function test_an_empty_blockers_map_is_a_json_object_on_the_wire(): void
    {
        $this->boot(self::reviewer());
        $recorded = MailFixtures::classified($this->recorder, 'wire', 'rfq', 0.9);
        $this->json('POST', '/api/v1/mail/threads/' . $recorded->threadId . '/review', ['action' => 'mark_read']);
        $raw = (string) $this->call('GET', '/api/v1/mail/threads/' . $recorded->threadId)->getBody();

        self::assertStringContainsString('"blockers":{', $raw);
        self::assertStringContainsString('"decision":null', $raw);
    }

    public function test_the_contract_file_agrees_when_it_is_beside_this_repository(): void
    {
        $path = dirname(__DIR__, 3) . '/tricab-web/src/api/mail/types.ts';
        if (!is_file($path)) {
            self::markTestSkipped('tricab-web is not beside tricab-api; the field names are pinned in SHAPES instead.');
        }
        $ts = (string) file_get_contents($path);

        foreach (self::SHAPES as $interface => $fields) {
            preg_match_all('/^  (\w+)\??:/m', self::interfaceBody($ts, $interface), $names);
            self::assertSame($fields, $names[1], $interface . ' fields');
        }

        self::assertSame(MailVocabulary::LABELS, self::union($ts, 'MailLabel'));
        self::assertSame(MailVocabulary::QUEUES, self::union($ts, 'MailQueueId'));
        self::assertSame(MailVocabulary::REVIEW_STATES, self::union($ts, 'MailReviewState'));
        self::assertSame(MailVocabulary::ACTIONS, self::union($ts, 'MailAction'));
        self::assertSame(MailVocabulary::SORTS, self::union($ts, 'MailSort'));
        self::assertSame(MailVocabulary::BLOCKERS, self::inlineUnion($ts, 'MailBlocker', 'kind'));
        self::assertSame(MailVocabulary::ACTIVITY_KINDS, self::inlineUnion($ts, 'MailActivityEvent', 'kind'));
        self::assertSame(MailVocabulary::CONNECTIONS, self::inlineUnion($ts, 'MailMailbox', 'connection'));
        self::assertSame(MailVocabulary::ATTACHMENT_FILTERS, self::inlineUnion($ts, 'MailThreadQuery', 'attachments'));
        self::assertSame(['confirm', 'correct'], self::inlineUnion($ts, 'MailLabelDecision', 'kind'));
    }

    /** @return list<string> */
    private static function union(string $ts, string $type): array
    {
        self::assertSame(1, preg_match("/export type {$type} = ([^\n]+)/", $ts, $m), $type);
        preg_match_all("/'(\w+)'/", $m[1], $values);

        return $values[1];
    }

    private static function interfaceBody(string $ts, string $interface): string
    {
        if (preg_match('/export interface ' . $interface . ' \{\n(.*?)\n\}/s', $ts, $m) !== 1) {
            self::fail($interface . ' is not in types.ts');
        }

        return $m[1];
    }

    /** @return list<string> */
    private static function inlineUnion(string $ts, string $interface, string $field): array
    {
        self::assertSame(1, preg_match('/^  ' . $field . '\??: ([^\n]+)/m', self::interfaceBody($ts, $interface), $f), $interface . '.' . $field);
        preg_match_all("/'(\w+)'/", $f[1], $values);

        return $values[1];
    }
}
