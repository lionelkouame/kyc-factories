<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Command\ValidateKyc;
use App\Application\Handler\ValidateKycHandler;
use App\Domain\KycRequest\Exception\KycDomainException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/kyc/{id}/validate', methods: ['POST'])]
final class ValidateKycController
{
    public function __construct(
        private readonly ValidateKycHandler $handler,
    ) {
    }

    public function __invoke(Request $request, string $id): JsonResponse
    {
        try {
            $this->handler->handle(new ValidateKyc($id));
        } catch (KycDomainException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
