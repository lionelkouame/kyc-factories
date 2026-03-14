<?php

declare(strict_types=1);

namespace App\Domain\KycRequest\Port;

/**
 * Résultat retourné par OcrPort après une extraction réussie.
 * Le score de confiance peut indiquer une extraction de faible qualité.
 */
final readonly class OcrExtractionResult
{
    public function __construct(
        public ?string $lastName,
        public ?string $firstName,
        public ?string $birthDate,
        public ?string $expiryDate,
        public ?string $documentId,
        public ?string $mrz,
        public float $confidenceScore,
    ) {
    }
}
