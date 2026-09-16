<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\TriExpense\Finance;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\ExpenseActorFactory;
use TriCab\Api\Http\Auth\ExpenseErrorMapper;
use TriCab\Core\Expense\Application\Service\RenderFinanceSummaryPdfService;

final class RenderFinanceSummaryPdfAction
{
    public function __construct(
        private readonly RenderFinanceSummaryPdfService $service,
        private readonly ExpenseErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($args);
        try {
            $params = $request->getQueryParams();
            $country = isset($params['country']) ? (string) $params['country'] : null;

            $pdf = $this->service->execute(
                ExpenseActorFactory::fromRequest($request),
                $country !== null && trim($country) !== '' ? $country : null,
            );

            $response->getBody()->write($pdf);

            return $response
                ->withHeader('Content-Type', 'application/pdf')
                ->withHeader('Content-Disposition', 'attachment; filename="finance-summary.pdf"')
                ->withHeader('Content-Length', (string) strlen($pdf))
                ->withHeader('Cache-Control', 'no-store, private');
        } catch (Throwable $e) {
            return $this->errors->map($e);
        }
    }
}
