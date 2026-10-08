<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Task;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Task\Domain\TaskFailure;
use TriCab\Core\Finance\Task\Domain\TaskImportFailed;
use TriCab\Core\Finance\Task\Service\TaskImportService;

/**
 * POST /api/v1/finance/task/import (source route 47): `{filename, data (base64), dry_run}`. A dry run is the
 * default (anything but a JSON `false`) and writes nothing; a real run loads the projects and tasks the
 * report said it would, row by row, and writes one audit event. Gated on `FINANCE.TASK.TASKS.CREATE`;
 * creating projects needs `FINANCE.TASK.SETUP.MANAGE` inside, as the source did.
 *
 * The file is at most 5 MB, which is about 7 MB as base64. This route is the only one that takes a body that
 * large, so the in-app guard is here, on this route only; nginx's own `client_max_body_size` for it is a
 * deployment setting (see the Phase 2 results).
 */
final class ImportTasksAction
{
    use TaskActionSupport;

    /** 5 MB of file as base64 (4 bytes for every 3), with the JSON around it. */
    private const MAX_BODY_BYTES = 7 * 1024 * 1024 + 4096;

    public function __construct(
        private readonly TaskImportService $service,
        private readonly JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        $actor = $this->actorOf($request);
        if ($actor === null) {
            return $this->unauthenticated($this->responder);
        }
        $declared = $request->getHeaderLine('Content-Length');
        if ($declared !== '' && ctype_digit($declared) && (int) $declared > self::MAX_BODY_BYTES) {
            return $this->failure($this->responder, new TaskImportFailed('The file is larger than 5 MB'));
        }
        $data = $this->bodyOf($request);
        $encoded = $data['data'] ?? '';
        $content = is_string($encoded) ? base64_decode($encoded, true) : false;
        if ($content === false) {
            return $this->failure($this->responder, new TaskImportFailed('The file could not be read'));
        }
        $filename = $data['filename'] ?? '';
        try {
            return $this->ok($this->responder, $this->service->run(
                $actor,
                is_string($filename) ? $filename : '',
                $content,
                ($data['dry_run'] ?? null) !== false,
            ));
        } catch (TaskFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
