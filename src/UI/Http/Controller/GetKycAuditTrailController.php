<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Handler\GetKycAuditTrailHandler;
use App\Application\Query\GetKycAuditTrail;
use App\Domain\KycRequest\Exception\KycRequestNotFoundException;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Projections')]
#[OA\Get(
    path: '/api/kyc/{id}/audit',
    summary: 'Obtenir l\'audit trail complet d\'une demande KYC',
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'UUID de la demande KYC', schema: new OA\Schema(type: 'string', format: 'uuid'))],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Audit trail (séquence d\'événements)',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(
                        property: 'entries',
                        type: 'array',
                        items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'eventType', type: 'string', example: 'kyc_request.approved'),
                                new OA\Property(property: 'occurredAt', type: 'string', format: 'date-time'),
                                new OA\Property(property: 'version', type: 'integer'),
                            ],
                        ),
                    ),
                ],
            ),
        ),
        new OA\Response(response: 404, description: 'Demande introuvable'),
    ],
)]
#[Route('/api/kyc/{id}/audit', methods: ['GET'])]
final class GetKycAuditTrailController
{
    public function __construct(
        private readonly GetKycAuditTrailHandler $handler,
    ) {
    }

    public function __invoke(Request $request, string $id): JsonResponse
    {
        try {
            $view = $this->handler->handle(new GetKycAuditTrail($id));
        } catch (KycRequestNotFoundException) {
            return new JsonResponse(['error' => sprintf('No audit trail found for KycRequest "%s".', $id)], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'kycRequestId' => $view->kycRequestId,
            'entries' => array_map(static fn ($entry) => [
                'eventId'    => $entry->eventId,
                'eventType'  => $entry->eventType,
                'payload'    => $entry->payload,
                'occurredAt' => $entry->occurredAt->format(\DateTimeInterface::ATOM),
                'version'    => $entry->version,
            ], $view->entries),
        ]);
    }
}
