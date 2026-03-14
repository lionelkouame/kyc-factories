<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Handler\GetKycRequestStatusHandler;
use App\Application\Query\GetKycRequestStatus;
use App\Domain\KycRequest\Exception\KycRequestNotFoundException;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Projections')]
#[OA\Get(
    path: '/api/kyc/{id}/status',
    summary: 'Consulter le statut courant d\'une demande KYC',
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'UUID de la demande KYC', schema: new OA\Schema(type: 'string', format: 'uuid'))],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Statut courant',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'kycRequestId', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'applicantId', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'documentType', type: 'string', enum: ['cni', 'passeport', 'titre_de_sejour']),
                    new OA\Property(property: 'status', type: 'string', enum: ['submitted', 'document_uploaded', 'document_rejected', 'ocr_completed', 'ocr_failed', 'approved', 'rejected', 'under_manual_review']),
                    new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
                ],
            ),
        ),
        new OA\Response(response: 404, description: 'Demande introuvable'),
    ],
)]
#[Route('/api/kyc/{id}/status', methods: ['GET'])]
final class GetKycRequestStatusController
{
    public function __construct(
        private readonly GetKycRequestStatusHandler $handler,
    ) {
    }

    public function __invoke(Request $request, string $id): JsonResponse
    {
        try {
            $view = $this->handler->handle(new GetKycRequestStatus($id));
        } catch (KycRequestNotFoundException) {
            return new JsonResponse(['error' => sprintf('KycRequest "%s" not found.', $id)], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'kycRequestId' => $view->kycRequestId,
            'applicantId'  => $view->applicantId,
            'documentType' => $view->documentType,
            'status'       => $view->status,
            'updatedAt'    => $view->updatedAt->format(\DateTimeInterface::ATOM),
        ]);
    }
}
