<?php

declare(strict_types=1);

namespace App\Application\Query\ReadModel;

/**
 * Piste d'audit complète d'une demande KYC.
 * Contient tous les événements dans l'ordre chronologique.
 */
final readonly class KycAuditTrailView
{
    /**
     * @param AuditTrailEntry[] $entries
     */
    public function __construct(
        public string $kycRequestId,
        public array $entries,
    ) {
    }
}
