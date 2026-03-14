<?php

declare(strict_types=1);

namespace App\Tests\Contract\DocumentStorage;

use App\Domain\KycRequest\Port\DocumentStoragePort;
use PHPUnit\Framework\TestCase;

/**
 * Suite de contrat pour DocumentStoragePort.
 *
 * Tout adaptateur implémentant DocumentStoragePort doit passer ces tests.
 * Étendre cette classe et implémenter createStorage().
 */
abstract class DocumentStorageContractTest extends TestCase
{
    abstract protected function createStorage(): DocumentStoragePort;

    private DocumentStoragePort $storage;

    protected function setUp(): void
    {
        $this->storage = $this->createStorage();
    }

    public function testStoreAndRetrieveRoundtrip(): void
    {
        $content = 'fake-jpeg-content';
        $path = $this->storage->store('kyc-id-1', $content, 'image/jpeg');

        self::assertNotEmpty($path);
        self::assertSame($content, $this->storage->retrieve($path));
    }

    public function testStoreReturnsDifferentPathsForDifferentRequests(): void
    {
        $path1 = $this->storage->store('kyc-id-1', 'content-a', 'image/jpeg');
        $path2 = $this->storage->store('kyc-id-2', 'content-b', 'image/jpeg');

        self::assertNotSame($path1, $path2);
    }

    public function testStoreReturnsDifferentPathsForDifferentContents(): void
    {
        $path1 = $this->storage->store('kyc-id-1', 'content-a', 'image/jpeg');
        $path2 = $this->storage->store('kyc-id-1', 'content-b', 'image/jpeg');

        self::assertNotSame($path1, $path2);
    }

    public function testDeleteRemovesFile(): void
    {
        $path = $this->storage->store('kyc-id-1', 'content', 'image/png');
        $this->storage->delete($path);

        $this->expectException(\RuntimeException::class);
        $this->storage->retrieve($path);
    }

    public function testRetrieveThrowsForUnknownPath(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->storage->retrieve('documents/nonexistent/file.jpg');
    }

    public function testStoreSupportsPdfMimeType(): void
    {
        $content = '%PDF-1.4 fake content';
        $path = $this->storage->store('kyc-id-1', $content, 'application/pdf');

        self::assertSame($content, $this->storage->retrieve($path));
    }

    public function testMultipleFilesCanCoexist(): void
    {
        $path1 = $this->storage->store('kyc-id-1', 'doc1', 'image/jpeg');
        $path2 = $this->storage->store('kyc-id-1', 'doc2', 'image/png');

        self::assertSame('doc1', $this->storage->retrieve($path1));
        self::assertSame('doc2', $this->storage->retrieve($path2));
    }
}
