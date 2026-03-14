<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Handler\GetKycRequestStatusHandler;
use App\Application\Query\GetKycRequestStatus;
use App\Domain\KycRequest\Exception\KycRequestNotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

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
