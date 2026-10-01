<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\CustomerContactReport;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportFileFailure;

final class CustomerContactReportUpload
{
    /**
     * @return array{name: string, bytes: string, mediaType: ?string, fields: array<string, mixed>}
     */
    public static function read(ServerRequestInterface $request): array
    {
        $uploaded = $request->getUploadedFiles()['file'] ?? null;
        if (!$uploaded instanceof UploadedFileInterface || $uploaded->getError() !== UPLOAD_ERR_OK) {
            throw CustomerContactReportFileFailure::invalid('No file was received.');
        }
        $size = $uploaded->getSize();
        if ($size !== null && $size > 15728640) {
            throw CustomerContactReportFileFailure::tooLarge();
        }
        $bytes = (string) $uploaded->getStream()->getContents();
        $name = $uploaded->getClientFilename();
        $media = $uploaded->getClientMediaType();
        $parsed = $request->getParsedBody();
        $fields = [];
        if (is_array($parsed)) {
            foreach ($parsed as $key => $value) {
                if (is_string($key)) {
                    $fields[$key] = $value;
                }
            }
        }

        return [
            'name' => is_string($name) && $name !== '' ? $name : 'attachment',
            'bytes' => $bytes,
            'mediaType' => is_string($media) ? $media : null,
            'fields' => $fields,
        ];
    }
}
