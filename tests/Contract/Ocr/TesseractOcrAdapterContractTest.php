<?php

declare(strict_types=1);

namespace App\Tests\Contract\Ocr;

use App\Domain\KycRequest\Port\OcrPort;
use App\Infrastructure\Ocr\TesseractOcrAdapter;

/**
 * Vérifie que TesseractOcrAdapter respecte le contrat OcrPort.
 *
 * Ce test nécessite que l'exécutable `tesseract` soit disponible dans le PATH
 * ainsi que la bibliothèque de langue `fra`.
 * Il est automatiquement ignoré si tesseract n'est pas installé.
 */
final class TesseractOcrAdapterContractTest extends OcrPortContractTest
{
    protected function setUp(): void
    {
        if (null === shell_exec('which tesseract 2>/dev/null')) {
            self::markTestSkipped('tesseract n\'est pas installé — test de contrat ignoré.');
        }

        parent::setUp();
    }

    protected function createAdapter(): OcrPort
    {
        return new TesseractOcrAdapter();
    }

    protected function validFileContent(): string
    {
        // Génère un PNG minimaliste avec du texte lisible par Tesseract.
        // On utilise la bibliothèque GD intégrée à PHP.
        if (!\extension_loaded('gd')) {
            self::markTestSkipped('Extension GD requise pour générer une image de test.');
        }

        $image = imagecreatetruecolor(400, 200);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);

        imagefilledrectangle($image, 0, 0, 400, 200, $white);
        imagestring($image, 5, 10, 20, 'NOM : DUPONT', $black);
        imagestring($image, 5, 10, 50, 'PRENOM : Jean', $black);
        imagestring($image, 5, 10, 80, 'NE LE 15.06.1990', $black);
        imagestring($image, 5, 10, 110, 'VALABLE JUSQU AU 01.01.2030', $black);
        imagestring($image, 5, 10, 140, 'N FR123456789', $black);
        imagestring($image, 3, 10, 170, 'IDFRADUPONT<<JEAN<<<<<<<<<<<<<<', $black);

        ob_start();
        imagepng($image);
        $content = (string) ob_get_clean();
        imagedestroy($image);

        return $content;
    }

    protected function invalidFileContent(): string
    {
        // Contenu non-image → Tesseract ne peut pas le traiter → OcrExtractionException
        return 'not-an-image-binary-content-corrupt';
    }
}
