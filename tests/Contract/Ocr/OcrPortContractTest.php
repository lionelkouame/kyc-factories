<?php

declare(strict_types=1);

namespace App\Tests\Contract\Ocr;

use App\Domain\KycRequest\Port\OcrExtractionException;
use App\Domain\KycRequest\Port\OcrExtractionResult;
use App\Domain\KycRequest\Port\OcrPort;
use PHPUnit\Framework\TestCase;

/**
 * Suite de contrat pour OcrPort.
 *
 * Tout adaptateur implémentant OcrPort doit passer ces tests.
 * Étendre cette classe et implémenter les méthodes abstraites.
 */
abstract class OcrPortContractTest extends TestCase
{
    /** Retourne l'adaptateur à tester. */
    abstract protected function createAdapter(): OcrPort;

    /**
     * Retourne un contenu de fichier (ou un chemin selon l'adaptateur)
     * qui produit une extraction réussie avec un score de confiance acceptable.
     */
    abstract protected function validFileContent(): string;

    /**
     * Retourne un contenu (ou chemin) qui provoque une OcrExtractionException
     * (fichier corrompu ou timeout).
     */
    abstract protected function invalidFileContent(): string;

    private OcrPort $adapter;

    protected function setUp(): void
    {
        $this->adapter = $this->createAdapter();
    }

    // ── Contrat : extraction réussie ─────────────────────────────────────────

    public function testExtractWithValidContentReturnsOcrExtractionResult(): void
    {
        $result = $this->adapter->extract($this->validFileContent());

        self::assertInstanceOf(OcrExtractionResult::class, $result);
    }

    public function testExtractConfidenceScoreIsBetweenZeroAndHundred(): void
    {
        $result = $this->adapter->extract($this->validFileContent());

        self::assertGreaterThanOrEqual(0.0, $result->confidenceScore);
        self::assertLessThanOrEqual(100.0, $result->confidenceScore);
    }

    // ── Contrat : fichier invalide → exception ────────────────────────────────

    public function testExtractWithInvalidContentThrowsOcrExtractionException(): void
    {
        $this->expectException(OcrExtractionException::class);

        $this->adapter->extract($this->invalidFileContent());
    }

    public function testOcrExtractionExceptionHasNonEmptyFailureCode(): void
    {
        try {
            $this->adapter->extract($this->invalidFileContent());
            self::fail('OcrExtractionException attendue.');
        } catch (OcrExtractionException $e) {
            self::assertNotEmpty($e->getFailureCode());
            self::assertMatchesRegularExpression('/^E_OCR_/', $e->getFailureCode());
        }
    }
}
