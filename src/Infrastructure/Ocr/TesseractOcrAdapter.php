<?php

declare(strict_types=1);

namespace App\Infrastructure\Ocr;

use App\Domain\KycRequest\Port\OcrExtractionException;
use App\Domain\KycRequest\Port\OcrExtractionResult;
use App\Domain\KycRequest\Port\OcrPort;

/**
 * Adaptateur OCR réel utilisant Tesseract-OCR via la CLI.
 *
 * Options CLI :
 *   - Langue   : fra+eng
 *   - Moteur   : LSTM (oem=1)
 *   - Mise en page : Auto (psm=3)
 *   - Sortie   : hOCR (pour extraction du score de confiance x_wconf)
 *   - Timeout  : 30 secondes
 */
final class TesseractOcrAdapter implements OcrPort
{
    private const TIMEOUT_SECONDS = 30;
    private const TESSERACT_BIN   = 'tesseract';

    public function extract(string $fileContent): OcrExtractionResult
    {
        $tempFile = $this->writeTempFile($fileContent);

        try {
            $hocr = $this->runTesseract($tempFile);
        } finally {
            @unlink($tempFile);
        }

        $confidence = $this->parseConfidence($hocr);
        $plainText  = $this->hocrToPlainText($hocr);

        return new OcrExtractionResult(
            lastName:        $this->extractLastName($plainText),
            firstName:       $this->extractFirstName($plainText),
            birthDate:       $this->extractBirthDate($plainText),
            expiryDate:      $this->extractExpiryDate($plainText),
            documentId:      $this->extractDocumentId($plainText),
            mrz:             $this->extractMrz($plainText),
            confidenceScore: $confidence,
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Execution Tesseract
    // ──────────────────────────────────────────────────────────────────────────

    private function writeTempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kyc_ocr_');
        if (false === $path || false === file_put_contents($path, $content)) {
            throw new OcrExtractionException('E_OCR_CORRUPT', 'Impossible d\'écrire le fichier temporaire pour l\'OCR.');
        }

        return $path;
    }

    private function runTesseract(string $filePath): string
    {
        $cmd = sprintf(
            '%s %s stdout -l fra+eng --oem 1 --psm 3 hocr 2>/dev/null',
            escapeshellcmd(self::TESSERACT_BIN),
            escapeshellarg($filePath),
        );

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptorSpec, $pipes);

        if (!\is_resource($process)) {
            throw new OcrExtractionException('E_OCR_CORRUPT', 'Impossible de démarrer le processus Tesseract.');
        }

        fclose($pipes[0]);

        // Attendre la fin du processus avec timeout
        $stdout  = '';
        $start   = time();
        $timedOut = false;

        while (!feof($pipes[1])) {
            if (time() - $start >= self::TIMEOUT_SECONDS) {
                $timedOut = true;
                break;
            }

            $chunk = fread($pipes[1], 8192);
            if (false !== $chunk) {
                $stdout .= $chunk;
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($timedOut) {
            throw new OcrExtractionException(
                'E_OCR_TIMEOUT',
                sprintf('Tesseract a dépassé le délai de %d secondes.', self::TIMEOUT_SECONDS),
            );
        }

        if (0 !== $exitCode || '' === trim($stdout)) {
            throw new OcrExtractionException('E_OCR_CORRUPT', 'Le fichier est illisible ou corrompu (code sortie Tesseract : ' . $exitCode . ').');
        }

        return $stdout;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Analyse hOCR
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Calcule la moyenne des scores x_wconf extraits du hOCR.
     * Retourne 0.0 si aucun mot reconnu.
     */
    private function parseConfidence(string $hocr): float
    {
        preg_match_all('/x_wconf\s+(\d+)/', $hocr, $matches);

        if ([] === $matches[1]) {
            return 0.0;
        }

        $scores = array_map('intval', $matches[1]);

        return array_sum($scores) / \count($scores);
    }

    /**
     * Extrait le texte brut depuis le hOCR en supprimant les balises HTML.
     */
    private function hocrToPlainText(string $hocr): string
    {
        $text = strip_tags($hocr);
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Extraction des champs par regex
    // ──────────────────────────────────────────────────────────────────────────

    private function extractLastName(string $text): ?string
    {
        // Patterns : "NOM : DUPONT", "NOM: DUPONT", "Nom DUPONT"
        if (preg_match('/\bNOM\s*:?\s*([A-ZÀ-Ÿ][A-ZÀ-Ÿ\-]{1,49})/u', $text, $m)) {
            return $m[1];
        }

        return null;
    }

    private function extractFirstName(string $text): ?string
    {
        // Patterns : "PRÉNOM : Jean", "PRENOM: Jean"
        if (preg_match('/\bPR[ÉE]NOM\s*:?\s*([A-ZÀ-Ÿa-zà-ÿ][A-ZÀ-Ÿa-zà-ÿ\-]{1,49})/u', $text, $m)) {
            return $m[1];
        }

        return null;
    }

    private function extractBirthDate(string $text): ?string
    {
        // Patterns : "NÉ LE 15.01.1990", "NÉE LE 1990-01-15", "DATE DE NAISSANCE 15/01/1990"
        if (preg_match('/N[ÉE]E?\s+LE\s+(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})/ui', $text, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }

        if (preg_match('/DATE\s+DE\s+NAISSANCE\s*:?\s*(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})/ui', $text, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }

        return null;
    }

    private function extractExpiryDate(string $text): ?string
    {
        // Patterns : "VALABLE JUSQU'AU 01.01.2030", "EXPIRE LE 2030-01-01", "DATE D'EXPIRATION"
        if (preg_match('/(?:VALABLE\s+JUSQU\'?AU|EXPIRE\s+LE|DATE\s+D\'?EXPIRATION\s*:?)\s*(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})/ui', $text, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }

        return null;
    }

    private function extractDocumentId(string $text): ?string
    {
        // Patterns : "N° AB1234567", "N° : FR123456789", "NUMÉRO AB1234567"
        if (preg_match('/(?:N°|NUM[ÉE]RO)\s*:?\s*([A-Z0-9]{9,12})\b/ui', $text, $m)) {
            return strtoupper($m[1]);
        }

        return null;
    }

    /**
     * Extrait la MRZ : 2 lignes consécutives de 30 ou 44 caractères alphanumériques + '<'.
     */
    private function extractMrz(string $text): ?string
    {
        // Les lignes MRZ contiennent uniquement A-Z0-9 et '<'
        preg_match_all('/\b([A-Z0-9<]{30}|[A-Z0-9<]{44})\b/', $text, $matches);

        if (\count($matches[1]) >= 2) {
            return $matches[1][0] . "\n" . $matches[1][1];
        }

        return null;
    }
}
