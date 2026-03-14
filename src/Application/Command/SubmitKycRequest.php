<?php

declare(strict_types=1);

namespace App\Application\Command;

final readonly class SubmitKycRequest
{
    public function __construct(
        public string $kycRequestId,
        public string $applicantId,
        public string $documentType,
    ) {
    }
}
