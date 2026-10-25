<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Finance\Task\Service\TaskMentions;

final class TaskMentionsTest extends TestCase
{
    public function test_it_reads_each_person_once_in_order(): void
    {
        self::assertSame([12, 3], TaskMentions::ids('Hi @[Max M](user:12), @[Una](user:3) and @[Max Again](user:12)'));
    }

    public function test_plain_text_and_malformed_tokens_mention_nobody(): void
    {
        foreach (['', 'email me@example.com', '@Una', '@[Una](user:)', '@[Una](user:0)', '@[Una](user:x)', '@[](user:5)', '[Una](user:5)'] as $notes) {
            self::assertSame([], TaskMentions::ids($notes), $notes);
        }
    }

    public function test_a_name_with_odd_characters_still_parses(): void
    {
        self::assertSame([7], TaskMentions::ids("Ping @[Zoë O'Neil-Smith](user:7)\nthanks"));
    }
}
