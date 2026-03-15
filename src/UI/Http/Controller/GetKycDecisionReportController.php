<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Handler\GetKycDecisionReportHandler;
use App\Application\Query\GetKycDecisionReport;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Rapports')]
#[OA\Get(
    path: '/api/kyc/reports/decisions',
    summary: 'Rapport de décisions KYC sur une période',
    parameters: [
        new OA\Parameter(name: 'from', in: 'query', required: true, description: 'Date de début (YYYY-MM-DD)', schema: new OA\Schema(type: 'string', format: 'date', example: '2024-01-01')),
        new OA\Parameter(name: 'to', in: 'query', required: true, description: 'Date de fin (YYYY-MM-DD)', schema: new OA\Schema(type: 'string', format: 'date', example: '2024-12-31')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Rapport de décisions',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'dateFrom', type: 'string', format: 'date'),
                    new OA\Property(property: 'dateTo', type: 'string', format: 'date'),
                    new OA\Property(property: 'approvedCount', type: 'integer'),
                    new OA\Property(property: 'rejectedCount', type: 'integer'),
                    new OA\Property(property: 'inProgressCount', type: 'integer'),
                    new OA\Property(property: 'approvalRate', type: 'number', format: 'float', description: 'Taux d\'approbation (0.0–1.0)'),
                ],
            ),
        ),
        new OA\Response(response: 400, description: 'Paramètre de date invalide'),
    ],
)]
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
