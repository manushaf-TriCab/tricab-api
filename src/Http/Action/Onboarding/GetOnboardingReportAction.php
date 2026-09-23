<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Core\Onboarding\Application\DTO\Report\ItemComplianceRow;
use TriCab\Core\Onboarding\Application\DTO\Report\OverdueWorkRow;
use TriCab\Core\Onboarding\Application\Service\ReadOnboardingReportsService;

/**
 * GET /api/v1/admin/onboarding/reports/{summary,items,overdue} — the HR
 * reports (4.5).
 *
 * Which report is a route argument, set per path in the route table, as the
 * item type is on the content routes: a client cannot name a report the table
 * does not list.
 */
final class GetOnboardingReportAction
{
    public function __construct(
        private readonly ReadOnboardingReportsService $reports,
        private readonly OnboardingSelfServiceResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $arguments
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $arguments = [],
    ): ResponseInterface {
        unset($response);
        $report = $arguments['report'] ?? '';

        return $this->responder->run($request, function () use ($request, $report): ResponseInterface {
            if ($report === 'summary') {
                return $this->responder->success($this->reports->summary()->toArray());
            }

            $query = QueryParams::normalize($request->getQueryParams());
            $page = QueryParams::int($query, 'page', 1);
            $pageSize = QueryParams::int($query, 'pageSize', 25);
            $result = $report === 'overdue'
                ? $this->reports->overdueWork($page, $pageSize)
                : $this->reports->itemCompliance($page, $pageSize);

            return $this->responder->success([
                'asOf' => $result['asOf'],
                'items' => array_map(static fn (ItemComplianceRow|OverdueWorkRow $row) => $row->toArray(), $result['items']),
                'pagination' => [
                    'page' => $result['page'],
                    'pageSize' => $result['pageSize'],
                    'totalItems' => $result['total'],
                    'totalPages' => (int) max(1, ceil($result['total'] / $result['pageSize'])),
                ],
            ]);
        });
    }
}
