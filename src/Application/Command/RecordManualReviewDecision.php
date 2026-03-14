<?php

declare(strict_types=1);

namespace App\Application\Command;

final readonly class RecordManualReviewDecision
{
    public function __construct(
        public string $kycRequestId,
        public string $reviewerId,
        public string $decision,
        public string $justification,
    ) {
    }
}
