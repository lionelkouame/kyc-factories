<?php

declare(strict_types=1);

namespace App\Application\Projection;

use App\Application\Query\ReadModel\KycAuditTrailView;

/**
 * Port secondaire — projection de la piste d'audit complète de chaque demande KYC.
 *
 * Conserve la liste ordonnée de tous les événements survenus sur chaque demande.
 */
interface KycAuditTrailProjectorPort extends ProjectorPort
{
    /**
     * Retourne la piste d'audit complète d'une demande, ou null si inconnue.
     */
    public function findByAggregateId(string $kycRequestId): ?KycAuditTrailView;
}
