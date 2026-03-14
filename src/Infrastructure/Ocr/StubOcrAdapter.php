<?php

declare(strict_types=1);

namespace App\Infrastructure\Ocr;

use App\Domain\KycRequest\Port\OcrExtractionException;
use App\Domain\KycRequest\Port\OcrExtractionResult;
use App\Domain\KycRequest\Port\OcrPort;

/**
 * Adaptateur OCR configurable pour les tests.
 *
 * Permet de programmer le résultat retourné ou l'exception levée
 * sans dépendre d'un moteur OCR réel.
 */
final class StubOcrAdapter implements OcrPort
{
    private ?OcrExtractionResult $result = null;
    private ?OcrExtractionException $exception = null;

    public function extract(string $fileContent): OcrExtractionResult
    {
        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->result ?? new OcrExtractionResult(
            lastName: 'DOE',
            firstName: 'John',
            birthDate: '1990-01-15',
            expiryDate: (new \DateTimeImmutable('+5 years'))->format('Y-m-d'),
            documentId: 'AB1234567',
            mrz: str_repeat('X', 44) . "\n" . str_repeat('X', 44),
            confidenceScore: 95.0,
        );
    }

    public function willReturn(OcrExtractionResult $result): void
    {
        $this->result = $result;
        $this->exception = null;
    }

    public function willThrow(OcrExtractionException $exception): void
    {
        $this->exception = $exception;
        $this->result = null;
    }

    public function reset(): void
    {
        $this->result = null;
        $this->exception = null;
    }
}
