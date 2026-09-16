<?php
declare(strict_types=1);
namespace TriCab\Api\Http\Action\TriExpense;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ExpenseActorFactory;
use TriCab\Api\Http\Auth\ExpenseErrorMapper;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Expense\Application\Service\GetExpenseReceiptService;
final class GetExpenseReceiptAction {
    public function __construct(private readonly GetExpenseReceiptService $service, private readonly JsonResponder $responder, private readonly ExpenseErrorMapper $errors) {}
    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface {
        unset($response);
        try {
            $actor = ExpenseActorFactory::fromRequest($request);
            return $this->responder->success(TriExpenseDtoSerializer::receipt($this->service->execute($actor, (string)($args['id'] ?? ''))));
        } catch (Throwable $e) { return $this->errors->map($e); }
    }
}