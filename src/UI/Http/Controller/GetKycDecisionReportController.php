<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Handler\GetKycDecisionReportHandler;
use App\Application\Query\GetKycDecisionReport;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/kyc/reports/decisions', methods: ['GET'])]
final class GetKycDecisionReportController
{
    public function __construct(
        private readonly GetKycDecisionReportHandler $handler,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $from = $request->query->getString('from');
        $to   = $request->query->getString('to');

        if ($from === '' || $to === '') {
            return new JsonResponse(
                ['error' => 'Query parameters "from" and "to" (Y-m-d) are required.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        if (!$this->isValidDate($from) || !$this->isValidDate($to)) {
            return new JsonResponse(
                ['error' => '"from" and "to" must be valid dates in Y-m-d format.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $view = $this->handler->handle(new GetKycDecisionReport($from, $to));

        return new JsonResponse([
            'dateFrom'        => $view->dateFrom,
            'dateTo'          => $view->dateTo,
            'approvedCount'   => $view->approvedCount,
            'rejectedCount'   => $view->rejectedCount,
            'inProgressCount' => $view->inProgressCount,
            'approvalRate'    => $view->approvalRate,
        ]);
    }

    private function isValidDate(string $value): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $d !== false && $d->format('Y-m-d') === $value;
    }
}
