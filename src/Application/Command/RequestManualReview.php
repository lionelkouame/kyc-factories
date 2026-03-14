<?php

declare(strict_types=1);

namespace App\Application\Command;

final readonly class RequestManualReview
{
    public function __construct(
        public string $kycRequestId,
        public string $requestedBy,
        public string $reason,
    ) {
    }
}
