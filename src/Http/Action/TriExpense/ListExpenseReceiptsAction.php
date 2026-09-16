<?php
declare(strict_types=1);
namespace TriCab\Api\Http\Action\TriExpense;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ExpenseActorFactory;
use TriCab\Api\Http\Auth\ExpenseErrorMapper;
use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Support\JsonValue;
use TriCab\Core\Expense\Application\Service\ListMyExpenseReceiptsService;
final class ListExpenseReceiptsAction {
    public function __construct(private readonly ListMyExpenseReceiptsService $service, private readonly JsonResponder $responder, private readonly ExpenseErrorMapper $errors) {}
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
        unset($response);
        try {
            $actor = ExpenseActorFactory::fromRequest($request);
            $params = QueryParams::normalize($request->getQueryParams());
            $other = JsonValue::intOrNull($params, 'userId');
            $items = $this->service->execute($actor, $other > 0 ? $other : null);
            return $this->responder->success(['items' => TriExpenseDtoSerializer::receipts($items)]);
        } catch (Throwable $e) { return $this->errors->map($e); }
    }
}