<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Command\RecordManualReviewDecision;
use App\Application\Port\CommandBusPort;
use App\Domain\KycRequest\Exception\KycDomainException;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Révision manuelle')]
#[OA\Post(
    path: '/api/kyc/{id}/manual-review/decision',
    summary: 'Saisir la décision de révision manuelle',
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'UUID de la demande KYC', schema: new OA\Schema(type: 'string', format: 'uuid'))],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['reviewerId', 'decision', 'justification'],
            properties: [
                new OA\Property(property: 'reviewerId', type: 'string', example: 'reviewer-007'),
                new OA\Property(property: 'decision', type: 'string', enum: ['approved', 'rejected'], example: 'approved'),
                new OA\Property(property: 'justification', type: 'string', example: 'Document authentifié avec succès'),
            ],
        ),
    ),
    responses: [
        new OA\Response(response: 204, description: 'Décision enregistrée'),
        new OA\Response(response: 422, description: 'Erreur métier'),
    ],
)]
#[Route('/api/kyc/{id}/manual-review/decision', methods: ['POST'])]
final class RecordManualReviewDecisionController
{
    public function __construct(
        private readonly CommandBusPort $bus,
    ) {
    }

    public function __invoke(Request $request, string $id): JsonResponse
    {
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $request->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        try {
            $this->bus->dispatch(new RecordManualReviewDecision(
                kycRequestId: $id,
                reviewerId: $this->str($body, 'reviewerId'),
                decision: $this->str($body, 'decision'),
                justification: $this->str($body, 'justification'),
            ));
        } catch (KycDomainException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function str(mixed $body, string $key): string
    {
        if (!\is_array($body)) {
            return '';
        }
        $v = $body[$key] ?? null;

        return \is_string($v) ? $v : '';
    }
}
