<?php

declare(strict_types=1);

namespace App\Application\Projection;

use App\Application\Query\ReadModel\PendingManualReviewItem;

/**
 * Port secondaire — projection des demandes KYC en attente de révision manuelle.
 *
 * Maintient la liste des demandes dans l'état `under_manual_review`.
 */
interface PendingManualReviewProjectorPort extends ProjectorPort
{
    /**
     * Retourne toutes les demandes en attente de révision manuelle.
     *
     * @return PendingManualReviewItem[]
     */
    public function findAll(): array;
}
