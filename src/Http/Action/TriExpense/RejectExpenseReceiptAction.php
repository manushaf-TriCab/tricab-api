<?php
declare(strict_types=1);
namespace TriCab\Api\Http\Action\TriExpense;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ExpenseActorFactory;
use TriCab\Api\Http\Auth\ExpenseErrorMapper;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Expense\Application\Service\ExpenseApprovalService;
final class RejectExpenseReceiptAction {
    public function __construct(private readonly ExpenseApprovalService $service, private readonly JsonResponder $responder, private readonly ExpenseErrorMapper $errors) {}
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface {
        unset($response);
        try {
            $actor = ExpenseActorFactory::fromRequest($request);
            $body = JsonRequestBody::requireObject($request);
            $reason = isset($body['reason']) ? (string)$body['reason'] : '';
            return $this->responder->success(TriExpenseDtoSerializer::receipt($this->service->reject($actor, (string)($args['id'] ?? ''), $reason)));
        } catch (Throwable $e) { return $this->errors->map($e); }
    }
}