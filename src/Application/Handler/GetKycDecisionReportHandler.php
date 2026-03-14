<?php

declare(strict_types=1);

namespace App\Application\Handler;

use App\Application\Projection\KycDecisionReportProjectorPort;
use App\Application\Query\GetKycDecisionReport;
use App\Application\Query\ReadModel\KycDecisionReportView;

final class GetKycDecisionReportHandler
{
    public function __construct(
        private readonly KycDecisionReportProjectorPort $projector,
    ) {
    }

    public function handle(GetKycDecisionReport $query): KycDecisionReportView
    {
        $dateFrom = new \DateTimeImmutable($query->dateFrom);
        $dateTo   = new \DateTimeImmutable($query->dateTo);

        return $this->projector->getReport($dateFrom, $dateTo);
    }
}
