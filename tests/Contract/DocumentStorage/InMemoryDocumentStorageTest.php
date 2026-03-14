<?php

declare(strict_types=1);

namespace App\Tests\Contract\DocumentStorage;

use App\Domain\KycRequest\Port\DocumentStoragePort;
use App\Infrastructure\Storage\InMemoryDocumentStorage;

/**
 * Exécute le contrat DocumentStoragePort sur l'adaptateur in-memory.
 */
final class InMemoryDocumentStorageTest extends DocumentStorageContractTest
{
    protected function createStorage(): DocumentStoragePort
    {
        return new InMemoryDocumentStorage();
    }
}
