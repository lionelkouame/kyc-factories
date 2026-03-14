<?php

declare(strict_types=1);

namespace App\Domain\KycRequest\Port;

interface DocumentStoragePort
{
    /**
     * Stocke le contenu d'un fichier et retourne le chemin de stockage.
     */
    public function store(string $kycRequestId, string $fileContent, string $mimeType): string;

    /**
     * Récupère le contenu binaire d'un fichier stocké.
     */
    public function retrieve(string $storagePath): string;

    /**
     * Supprime physiquement un fichier (purge RGPD).
     */
    public function delete(string $storagePath): void;
}
