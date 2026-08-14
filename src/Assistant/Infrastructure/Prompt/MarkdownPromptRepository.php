<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Infrastructure\Prompt;

use RuntimeException;

final readonly class MarkdownPromptRepository
{
    public function __construct(
        private string $directory,
    ) {
    }

    public function get(string $name, string $version): VersionedPrompt
    {
        if (
            preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name) !== 1
            || preg_match('/^v[1-9][0-9]*$/', $version) !== 1
        ) {
            throw new RuntimeException('Prompt name or version is invalid.');
        }

        $path = rtrim($this->directory, '/\\')
            . DIRECTORY_SEPARATOR
            . $name
            . '-'
            . $version
            . '.md';
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Versioned prompt file is unavailable.');
        }

        $content = file_get_contents($path);
        if (!is_string($content) || trim($content) === '') {
            throw new RuntimeException('Versioned prompt file is empty.');
        }

        return new VersionedPrompt($name, $version, trim($content));
    }
}
