<?php

declare(strict_types=1);

namespace App\Tests\Contract\Ocr;

use App\Domain\KycRequest\Port\OcrExtractionException;
use App\Domain\KycRequest\Port\OcrExtractionResult;
use App\Domain\KycRequest\Port\OcrPort;
use App\Infrastructure\Ocr\StubOcrAdapter;

/**
 * Vérifie que StubOcrAdapter respecte le contrat OcrPort.
 */
final class StubOcrAdapterContractTest extends OcrPortContractTest
{
    private StubOcrAdapter $stub;

    protected function createAdapter(): OcrPort
    {
        $this->stub = new StubOcrAdapter();

        return $this->stub;
    }

    protected function validFileContent(): string
    {
        // Le stub ignore le contenu — on programme une réponse valide
        $this->stub->willReturn(new OcrExtractionResult(
            lastName: 'DUPONT',
            firstName: 'Jean',
            birthDate: '1990-06-15',
            expiryDate: (new \DateTimeImmutable('+5 years'))->format('Y-m-d'),
            documentId: 'FR123456789',
            mrz: str_pad('IDFRADUPONT', 30, '<') . "\n" . str_pad('FR123456789', 30, '<'),
            confidenceScore: 85.0,
        ));

        return 'valid-content-ignored-by-stub';
    }

    protected function invalidFileContent(): string
    {
        // Le stub ignore le contenu — on programme une exception
        $this->stub->willThrow(new OcrExtractionException('E_OCR_CORRUPT', 'Fichier corrompu.'));

        return 'corrupt-content-ignored-by-stub';
    }
}
