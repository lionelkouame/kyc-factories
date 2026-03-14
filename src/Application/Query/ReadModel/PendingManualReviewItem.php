<?php

declare(strict_types=1);

namespace App\Application\Query\ReadModel;

/**
 * Élément d'une demande KYC en attente de révision manuelle.
 * Alimentée par PendingManualReviewProjectorPort.
 */
final readonly class PendingManualReviewItem
{
    public function __construct(
        public string $kycRequestId,
        public string $applicantId,
        public string $requestedBy,
        public string $reason,
        public \DateTimeImmutable $requestedAt,
    ) {
    }
}
