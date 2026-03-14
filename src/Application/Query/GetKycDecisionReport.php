<?php

declare(strict_types=1);

namespace App\Application\Query;

/**
 * Requête de rapport de décisions KYC sur une période.
 *
 * @param string $dateFrom ISO date (Y-m-d) inclusive
 * @param string $dateTo   ISO date (Y-m-d) inclusive
 */
final readonly class GetKycDecisionReport
{
    public function __construct(
        public string $dateFrom,
        public string $dateTo,
    ) {
    }
}
