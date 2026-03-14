<?php

declare(strict_types=1);

namespace App\Application\Handler;

use App\Application\Projection\PendingManualReviewProjectorPort;
use App\Application\Query\ListPendingManualReviews;
use App\Application\Query\ReadModel\PendingManualReviewItem;

final class ListPendingManualReviewsHandler
{
    public function __construct(
        private readonly PendingManualReviewProjectorPort $projector,
    ) {
    }

    /**
     * @return PendingManualReviewItem[]
     */
    public function handle(ListPendingManualReviews $query): array
    {
        return $this->projector->findAll();
    }
}
