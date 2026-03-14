<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Command\UploadDocument;
use App\Application\Port\CommandBusPort;
use App\Domain\KycRequest\Exception\KycDomainException;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Accepte un fichier encodé en base64 dans le corps JSON.
 *
 * Body attendu :
 * {
 *   "fileContent": "<base64>",
 *   "mimeType": "image/jpeg",
 *   "sizeBytes": 512000,
 *   "dpi": 300.0,
 *   "blurVariance": 150.0,
 *   "sha256Hash": "<hex>"
 * }
 */
#[OA\Tag(name: 'Pipeline KYC')]
#[OA\Post(
    path: '/api/kyc/{id}/document',
    summary: 'Uploader le document d\'identité (encodé en base64)',
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'UUID de la demande KYC', schema: new OA\Schema(type: 'string', format: 'uuid'))],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['fileContent', 'mimeType', 'sizeBytes', 'dpi', 'blurVariance', 'sha256Hash'],
            properties: [
                new OA\Property(property: 'fileContent', type: 'string', format: 'byte', description: 'Contenu du fichier encodé en base64'),
                new OA\Property(property: 'mimeType', type: 'string', example: 'image/jpeg', enum: ['image/jpeg', 'image/png', 'application/pdf']),
                new OA\Property(property: 'sizeBytes', type: 'integer', example: 512000),
                new OA\Property(property: 'dpi', type: 'number', format: 'float', example: 300.0),
                new OA\Property(property: 'blurVariance', type: 'number', format: 'float', example: 150.0),
                new OA\Property(property: 'sha256Hash', type: 'string', example: 'a3f1...'),
            ],
        ),
    ),
    responses: [
        new OA\Response(response: 204, description: 'Document accepté'),
        new OA\Response(response: 400, description: 'fileContent non valide (base64 attendu)'),
        new OA\Response(response: 422, description: 'Erreur métier (qualité insuffisante, mauvais état…)'),
    ],
)]
#[Route('/api/kyc/{id}/document', methods: ['POST'])]
final class UploadDocumentController
{
    public function __construct(
        private readonly CommandBusPort $bus,
    ) {
    }

    public function __invoke(Request $request, string $id): JsonResponse
    {
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $request->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        $fileContentB64 = $this->str($body, 'fileContent');
        $fileContent    = base64_decode($fileContentB64, true);

        if ($fileContent === false) {
            return new JsonResponse(['error' => 'fileContent must be a valid base64 string.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->bus->dispatch(new UploadDocument(
                kycRequestId: $id,
                fileContent: $fileContent,
                mimeType: $this->str($body, 'mimeType'),
                sizeBytes: $this->int($body, 'sizeBytes'),
                dpi: $this->float($body, 'dpi'),
                blurVariance: $this->float($body, 'blurVariance'),
                sha256Hash: $this->str($body, 'sha256Hash'),
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

    private function int(mixed $body, string $key): int
    {
        if (!\is_array($body)) {
            return 0;
        }
        $v = $body[$key] ?? null;

        return \is_numeric($v) ? (int) $v : 0;
    }

    private function float(mixed $body, string $key): float
    {
        if (!\is_array($body)) {
            return 0.0;
        }
        $v = $body[$key] ?? null;

        return \is_numeric($v) ? (float) $v : 0.0;
    }
}
