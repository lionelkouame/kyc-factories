<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Command\RecordManualReviewDecision;
use App\Application\Handler\RecordManualReviewDecisionHandler;
use App\Domain\KycRequest\Exception\KycDomainException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/kyc/{id}/manual-review/decision', methods: ['POST'])]
final class RecordManualReviewDecisionController
{
    public function __construct(
        private readonly RecordManualReviewDecisionHandler $handler,
    ) {
    }

    public function __invoke(Request $request, string $id): JsonResponse
    {
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $request->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        try {
            $this->handler->handle(new RecordManualReviewDecision(
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
