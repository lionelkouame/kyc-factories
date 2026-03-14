<?php

declare(strict_types=1);

namespace App\Application\Command;

final readonly class ExtractOcr
{
    public function __construct(
        public string $kycRequestId,
    ) {
    }
}
