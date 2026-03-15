<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Command\ValidateKyc;
use App\Application\Port\CommandBusPort;
use App\Domain\KycRequest\Exception\KycDomainException;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Pipeline KYC')]
#[OA\Post(
    path: '/api/kyc/{id}/validate',
    summary: 'Déclencher la validation métier (règles KYC)',
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'UUID de la demande KYC', schema: new OA\Schema(type: 'string', format: 'uuid'))],
    responses: [
        new OA\Response(response: 204, description: 'Validation effectuée (demande approuvée ou rejetée selon les règles)'),
        new OA\Response(response: 422, description: 'Erreur métier (mauvais état)'),
    ],
)]
#[Route('/api/kyc/{id}/validate', methods: ['POST'])]
final class ValidateKycController
{
    public function __construct(
        private readonly CommandBusPort $bus,
    ) {
    }

    public function __invoke(Request $request, string $id): JsonResponse
    {
        try {
            $this->bus->dispatch(new ValidateKyc($id));
        } catch (KycDomainException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
