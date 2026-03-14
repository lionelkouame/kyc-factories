<?php

declare(strict_types=1);

namespace App\Application\Query\ReadModel;

/**
 * Vue en lecture de l'état courant d'une demande KYC.
 * Alimentée par KycRequestStatusProjectorPort.
 */
final readonly class KycRequestStatusView
{
    public function __construct(
        public string $kycRequestId,
        public string $applicantId,
        public string $documentType,
        public string $status,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
