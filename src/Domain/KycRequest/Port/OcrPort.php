<?php

declare(strict_types=1);

namespace App\Domain\KycRequest\Port;

interface OcrPort
{
    /**
     * Extrait le texte d'un fichier document.
     *
     * @throws OcrExtractionException si Tesseract échoue (timeout, fichier corrompu…)
     */
    public function extract(string $fileContent): OcrExtractionResult;
}
