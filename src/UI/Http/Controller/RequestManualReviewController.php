<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Command\RequestManualReview;
use App\Application\Port\CommandBusPort;
use App\Domain\KycRequest\Exception\KycDomainException;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Révision manuelle')]
#[OA\Post(
    path: '/api/kyc/{id}/manual-review',
    summary: 'Déclencher une révision manuelle par un Compliance Officer',
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'UUID de la demande KYC', schema: new OA\Schema(type: 'string', format: 'uuid'))],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['requestedBy', 'reason'],
            properties: [
                new OA\Property(property: 'requestedBy', type: 'string', example: 'officer-42'),
                new OA\Property(property: 'reason', type: 'string', example: 'Document peu lisible'),
            ],
        ),
    ),
    responses: [
        new OA\Response(response: 204, description: 'Révision manuelle déclenchée'),
        new OA\Response(response: 422, description: 'Erreur métier'),
    ],
)]

#[Route('/api/kyc/{id}/manual-review', methods: ['POST'])]
final class RequestManualReviewController
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
            $this->bus->dispatch(new RequestManualReview(
                kycRequestId: $id,
                requestedBy: $this->str($body, 'requestedBy'),
                reason: $this->str($body, 'reason'),
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
