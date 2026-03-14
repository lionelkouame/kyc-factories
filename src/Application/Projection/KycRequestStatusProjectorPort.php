<?php

declare(strict_types=1);

namespace App\Application\Projection;

use App\Application\Query\ReadModel\KycRequestStatusView;

/**
 * Port secondaire — projection de l'état courant de chaque demande KYC.
 *
 * Tient à jour une vue dénormalisée (kycRequestId → status) pour les requêtes rapides.
 */
interface KycRequestStatusProjectorPort extends ProjectorPort
{
    /**
     * Retourne la vue de l'état courant d'une demande, ou null si inconnue.
     */
    public function findById(string $kycRequestId): ?KycRequestStatusView;

    /**
     * Retourne toutes les vues de statut connues.
     *
     * @return KycRequestStatusView[]
     */
    public function findAll(): array;
}
