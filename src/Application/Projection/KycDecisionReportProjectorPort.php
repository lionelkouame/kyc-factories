<?php

declare(strict_types=1);

namespace App\Application\Projection;

use App\Application\Query\ReadModel\KycDecisionReportView;

/**
 * Port secondaire — projection du rapport de décisions KYC.
 *
 * Agrège les événements KycApproved, KycRejected et KycRequestSubmitted
 * pour produire un rapport sur une période donnée.
 */
interface KycDecisionReportProjectorPort extends ProjectorPort
{
    /**
     * Retourne le rapport agrégé pour la période [dateFrom, dateTo] inclus.
     *
     * - approvedCount   : demandes approuvées pendant la période (date de KycApproved)
     * - rejectedCount   : demandes rejetées pendant la période (date de KycRejected)
     * - inProgressCount : demandes soumises pendant la période mais pas encore décidées
     * - approvalRate    : approvedCount / (approvedCount + rejectedCount) × 100, ou 0.0 si aucune décision
     */
    public function getReport(\DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo): KycDecisionReportView;
}
