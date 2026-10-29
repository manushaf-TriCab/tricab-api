<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\TriExpense\Finance;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use DateTimeImmutable;
use TriCab\Api\Http\Auth\ExpenseActorFactory;
use TriCab\Api\Http\Exception\HttpRequestValidationFailed;
use TriCab\Api\Http\Auth\ExpenseErrorMapper;
use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Support\JsonValue;
use TriCab\Core\Expense\Application\Service\ListExpenseAuditService;
use TriCab\Core\Expense\Contract\ExpenseAuditFilter;

final class ListExpenseAuditAction
{
    public function __construct(
        private readonly ListExpenseAuditService $service,
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
            $params = QueryParams::normalize($request->getQueryParams());
            $limit = JsonValue::intOrNull($params, 'limit');

            return $this->responder->success([
                'items' => $this->service->execute(
                    ExpenseActorFactory::fromRequest($request),
                    $limit !== null && $limit > 0 ? $limit : null,
                    $this->filter($params),
                ),
            ]);
        } catch (Throwable $e) {
            return $this->errors->map($e);
        }
    }

    /** @param array<string, mixed> $params */
    private function filter(array $params): ?ExpenseAuditFilter
    {
        $from = $this->day($params, 'from');
        $to = $this->day($params, 'to');
        $action = JsonValue::stringOrNull($params, 'action');
        $action = $action !== null && trim($action) !== '' ? trim($action) : null;
        $actor = JsonValue::intOrNull($params, 'actorUserId');
        if ($actor !== null && $actor < 1) {
            throw HttpRequestValidationFailed::unprocessable('REQUEST_INVALID', 'actorUserId must be a positive integer.');
        }
        if ($from !== null && $to !== null && $from > $to) {
            throw HttpRequestValidationFailed::unprocessable('REQUEST_INVALID', 'from must not be after to.');
        }
        if ($from === null && $to === null && $action === null && $actor === null) {
            return null;
        }

        return new ExpenseAuditFilter($from, $to, $action, $actor);
    }

    /** @param array<string, mixed> $params */
    private function day(array $params, string $key): ?DateTimeImmutable
    {
        $raw = JsonValue::stringOrNull($params, $key);
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', trim($raw), new \DateTimeZone('UTC'));
        if ($day === false || $day->format('Y-m-d') !== trim($raw)) {
            throw HttpRequestValidationFailed::unprocessable('REQUEST_INVALID', $key . ' must be a YYYY-MM-DD date.');
        }

        return $day;
    }
}
