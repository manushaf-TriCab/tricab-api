<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Config;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Config\ReleaseRevision;

final class ReleaseRevisionTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/release-revision-' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function test_reads_the_commits_the_release_build_wrote(): void
    {
        file_put_contents($this->path, '{"api":"b2a3545a","web":"9f1c2d3e"}' . "\n");

        $revision = ReleaseRevision::fromFile($this->path);

        self::assertNotNull($revision);
        self::assertSame('b2a3545a', $revision->api);
        self::assertSame('9f1c2d3e', $revision->web);
    }

    public function test_a_checkout_without_release_json_has_no_revision(): void
    {
        self::assertNull(ReleaseRevision::fromFile($this->path));
    }

    public function test_an_unreadable_manifest_is_no_revision_rather_than_an_error(): void
    {
        foreach (['not json', '[]', '{"api":"b2a3545a"}', '{"api":"","web":"9f1c2d3e"}', '{"api":1,"web":2}'] as $contents) {
            file_put_contents($this->path, $contents);

            self::assertNull(ReleaseRevision::fromFile($this->path), $contents);
        }
    }
}
