<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Command\SubmitKycRequest;
use App\Application\Port\CommandBusPort;
use App\Domain\KycRequest\Exception\KycDomainException;
use App\Domain\KycRequest\ValueObject\KycRequestId;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Pipeline KYC')]
#[OA\Post(
    path: '/api/kyc',
    summary: 'Soumettre une nouvelle demande KYC',
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['applicantId', 'documentType'],
            properties: [
                new OA\Property(property: 'applicantId', type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000'),
                new OA\Property(property: 'documentType', type: 'string', enum: ['cni', 'passeport', 'titre_de_sejour'], example: 'passeport'),
            ],
        ),
    ),
    responses: [
        new OA\Response(
            response: 201,
            description: 'Demande créée',
            content: new OA\JsonContent(
                properties: [new OA\Property(property: 'kycRequestId', type: 'string', format: 'uuid')],
            ),
        ),
        new OA\Response(response: 422, description: 'Erreur métier (domaine)'),
    ],
)]
#[Route('/api/kyc', methods: ['POST'])]
final class SubmitKycRequestController
{
    public function __construct(
        private readonly CommandBusPort $bus,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $request->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        $kycRequestId = KycRequestId::generate()->toString();
        $applicantId  = $this->str($body, 'applicantId');
        $documentType = $this->str($body, 'documentType');

        try {
            $this->bus->dispatch(new SubmitKycRequest($kycRequestId, $applicantId, $documentType));
        } catch (KycDomainException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\ValueError $e) {
            return new JsonResponse(['error' => sprintf('Invalid documentType value: "%s".', $documentType)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(
            ['kycRequestId' => $kycRequestId],
            Response::HTTP_CREATED,
        );
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
