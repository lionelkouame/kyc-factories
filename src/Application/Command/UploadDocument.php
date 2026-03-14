<?php

declare(strict_types=1);

namespace App\Application\Command;

final readonly class UploadDocument
{
    public function __construct(
        public string $kycRequestId,
        public string $fileContent,
        public string $mimeType,
        public int $sizeBytes,
        public float $dpi,
        public float $blurVariance,
        public string $sha256Hash,
    ) {
    }
}
