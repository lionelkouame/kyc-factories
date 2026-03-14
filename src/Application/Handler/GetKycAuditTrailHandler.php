<?php

declare(strict_types=1);

namespace App\Application\Handler;

use App\Application\Projection\KycAuditTrailProjectorPort;
use App\Application\Query\GetKycAuditTrail;
use App\Application\Query\ReadModel\KycAuditTrailView;
use App\Domain\KycRequest\Exception\KycRequestNotFoundException;

final class GetKycAuditTrailHandler
{
    public function __construct(
        private readonly KycAuditTrailProjectorPort $projector,
    ) {
    }

    public function handle(GetKycAuditTrail $query): KycAuditTrailView
    {
        $view = $this->projector->findByAggregateId($query->kycRequestId);

        if ($view === null) {
            throw new KycRequestNotFoundException(
                sprintf('No audit trail found for KycRequest "%s".', $query->kycRequestId),
            );
        }

        return $view;
    }
}
