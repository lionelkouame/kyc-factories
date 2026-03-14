<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Command\SubmitKycRequest;
use App\Application\Port\CommandBusPort;
use App\Domain\KycRequest\Exception\KycDomainException;
use App\Domain\KycRequest\ValueObject\KycRequestId;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

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
