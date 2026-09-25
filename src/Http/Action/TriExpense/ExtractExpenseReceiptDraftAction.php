<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\TriExpense;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;
use TriCab\Api\Http\Auth\ExpenseActorFactory;
use TriCab\Api\Http\Auth\ExpenseErrorMapper;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Expense\Application\Exception\ExpenseOcrUnavailable;
use TriCab\Core\Expense\Application\Service\ExtractExpenseReceiptDraftService;

/**
 * POST /api/v1/tri-expense/receipts/extract
 *
 * Multipart field `file`: temporary receipt bytes for assistive OCR before an
 * expense exists. Never creates a receipt or stores the file.
 */
final class ExtractExpenseReceiptDraftAction
{
    public function __construct(
        private readonly ExtractExpenseReceiptDraftService $service,
        private readonly JsonResponder $responder,
        private readonly ExpenseErrorMapper $errors,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response, $args);
        try {
            [$bytes, $fileName] = self::readUpload($request);

            return $this->responder->success(
                $this->service->execute(
                    ExpenseActorFactory::fromRequest($request),
                    $bytes,
                    $fileName,
                ),
            );
        } catch (Throwable $e) {
            return $this->errors->map($e);
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function readUpload(ServerRequestInterface $request): array
    {
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;

        if (!$file instanceof UploadedFileInterface) {
            throw ExpenseOcrUnavailable::inputRejected('missing_file');
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw ExpenseOcrUnavailable::inputRejected('upload_error_' . $file->getError());
        }

        $bytes = (string) $file->getStream()->getContents();
        $fileName = trim((string) ($file->getClientFilename() ?? ''));
        if ($fileName === '') {
            $fileName = 'receipt.bin';
        }

        return [$bytes, $fileName];
    }
}
