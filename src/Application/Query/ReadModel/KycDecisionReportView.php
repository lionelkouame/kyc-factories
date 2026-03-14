<?php

declare(strict_types=1);

namespace App\Application\Query\ReadModel;

/**
 * Rapport agrégé des décisions KYC sur une période donnée.
 * Alimenté par KycDecisionReportProjectorPort.
 */
final readonly class KycDecisionReportView
{
    public function __construct(
        public string $dateFrom,
        public string $dateTo,
        public int    $approvedCount,
        public int    $rejectedCount,
        public int    $inProgressCount,
        public float  $approvalRate,
    ) {
    }
}
