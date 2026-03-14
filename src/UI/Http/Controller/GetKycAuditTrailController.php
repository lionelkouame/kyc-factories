<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Handler\GetKycAuditTrailHandler;
use App\Application\Query\GetKycAuditTrail;
use App\Domain\KycRequest\Exception\KycRequestNotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

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
