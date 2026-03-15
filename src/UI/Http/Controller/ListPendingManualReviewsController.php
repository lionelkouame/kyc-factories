<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Handler\ListPendingManualReviewsHandler;
use App\Application\Query\ListPendingManualReviews;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Révision manuelle')]
#[OA\Get(
    path: '/api/kyc/pending-reviews',
    summary: 'Lister les demandes KYC en attente de révision manuelle',
    responses: [
        new OA\Response(
            response: 200,
            description: 'Liste des demandes en attente',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(
                        property: 'items',
                        type: 'array',
                        items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'kycRequestId', type: 'string', format: 'uuid'),
                                new OA\Property(property: 'requestedBy', type: 'string'),
                                new OA\Property(property: 'reason', type: 'string'),
                                new OA\Property(property: 'requestedAt', type: 'string', format: 'date-time'),
                            ],
                        ),
                    ),
                ],
            ),
        ),
    ],
)]
#[Route('/api/kyc/pending-reviews', methods: ['GET'])]
final class ListPendingManualReviewsController
{
    public function __construct(
        private readonly ListPendingManualReviewsHandler $handler,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $items = $this->handler->handle(new ListPendingManualReviews());

        return new JsonResponse(
            array_map(static fn ($item) => [
                'kycRequestId' => $item->kycRequestId,
                'applicantId'  => $item->applicantId,
                'requestedBy'  => $item->requestedBy,
                'reason'       => $item->reason,
                'requestedAt'  => $item->requestedAt->format(\DateTimeInterface::ATOM),
            ], $items),
        );
    }
}
