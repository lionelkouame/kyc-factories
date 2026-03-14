<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Handler\ListPendingManualReviewsHandler;
use App\Application\Query\ListPendingManualReviews;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

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
