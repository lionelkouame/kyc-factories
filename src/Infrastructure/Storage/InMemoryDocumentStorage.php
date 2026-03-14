<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Domain\KycRequest\Port\DocumentStoragePort;

/**
 * Adaptateur in-memory du stockage de documents.
 *
 * Stocke les fichiers dans un tableau associatif (storagePath → contenu).
 * Idéal pour les tests et les environnements sans système de fichiers.
 */
final class InMemoryDocumentStorage implements DocumentStoragePort
{
    /** @var array<string, string> storagePath → fileContent */
    private array $store = [];

    public function store(string $kycRequestId, string $fileContent, string $mimeType): string
    {
        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            default      => 'bin',
        };

        $storagePath = sprintf('documents/%s/%s.%s', $kycRequestId, sha1($fileContent), $extension);
        $this->store[$storagePath] = $fileContent;

        return $storagePath;
    }

    public function retrieve(string $storagePath): string
    {
        if (!isset($this->store[$storagePath])) {
            throw new \RuntimeException(sprintf('Document not found at path "%s".', $storagePath));
        }

        return $this->store[$storagePath];
    }

    public function delete(string $storagePath): void
    {
        unset($this->store[$storagePath]);
    }

    public function has(string $storagePath): bool
    {
        return isset($this->store[$storagePath]);
    }
}
