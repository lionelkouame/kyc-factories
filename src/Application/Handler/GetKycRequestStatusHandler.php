<?php

declare(strict_types=1);

namespace App\Application\Handler;

use App\Application\Projection\KycRequestStatusProjectorPort;
use App\Application\Query\GetKycRequestStatus;
use App\Application\Query\ReadModel\KycRequestStatusView;
use App\Domain\KycRequest\Exception\KycRequestNotFoundException;

final class GetKycRequestStatusHandler
{
    public function __construct(
        private readonly KycRequestStatusProjectorPort $projector,
    ) {
    }

    public function handle(GetKycRequestStatus $query): KycRequestStatusView
    {
        $view = $this->projector->findById($query->kycRequestId);

        if ($view === null) {
            throw new KycRequestNotFoundException(
                sprintf('No KycRequest found with id "%s".', $query->kycRequestId),
            );
        }

        return $view;
    }
}
