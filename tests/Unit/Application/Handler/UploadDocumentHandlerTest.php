<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Handler;

use App\Application\Command\UploadDocument;
use App\Application\Handler\UploadDocumentHandler;
use App\Application\Repository\KycRequestRepositoryPort;
use App\Domain\KycRequest\Aggregate\KycRequest;
use App\Domain\KycRequest\Aggregate\KycStatus;
use App\Domain\KycRequest\Event\DocumentRejectedOnUpload;
use App\Domain\KycRequest\Event\DocumentUploaded;
use App\Domain\KycRequest\Port\DocumentStoragePort;
use App\Domain\KycRequest\Port\DomainEventPublisherPort;
use App\Domain\KycRequest\ValueObject\ApplicantId;
use App\Domain\KycRequest\ValueObject\DocumentType;
use App\Domain\KycRequest\ValueObject\KycRequestId;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class UploadDocumentHandlerTest extends TestCase
{
    private KycRequestRepositoryPort&MockObject $repository;
    private DocumentStoragePort&MockObject $storage;
    private DomainEventPublisherPort&MockObject $publisher;
    private UploadDocumentHandler $handler;

    private KycRequestId $id;
    private KycRequest $submittedRequest;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(KycRequestRepositoryPort::class);
        $this->storage = $this->createMock(DocumentStoragePort::class);
        $this->publisher = $this->createMock(DomainEventPublisherPort::class);
        $this->handler = new UploadDocumentHandler($this->repository, $this->storage, $this->publisher);

        $this->id = KycRequestId::generate();
        $this->submittedRequest = KycRequest::submit(
            $this->id,
            ApplicantId::fromString('550e8400-e29b-41d4-a716-446655440000'),
            DocumentType::Cni,
        );
        $this->submittedRequest->releaseEvents(); // vide les événements initiaux
    }

    private function validCommand(): UploadDocument
    {
        return new UploadDocument(
            kycRequestId: $this->id->toString(),
            fileContent: 'binary_content',
            mimeType: 'image/jpeg',
            sizeBytes: 1_024_000,
            dpi: 300.0,
            blurVariance: 120.0,
            sha256Hash: 'abc123',
        );
    }

    // ── Parcours nominal ─────────────────────────────────────────────────────

    public function testValidUploadProducesDocumentUploadedEvent(): void
    {
        $this->repository->method('get')->willReturn($this->submittedRequest);
        $this->storage->method('store')->willReturn('documents/kyc_abc.jpg');

        $publishedEvents = null;
        $this->publisher->method('publishAll')
            ->willReturnCallback(function (array $events) use (&$publishedEvents): void {
                $publishedEvents = $events;
            });

        $capturedAggregate = null;
        $this->repository->method('save')
            ->willReturnCallback(function (KycRequest $agg) use (&$capturedAggregate): void {
                $capturedAggregate = $agg;
            });

        $this->handler->handle($this->validCommand());

        self::assertSame(KycStatus::DocumentUploaded, $capturedAggregate?->getStatus());
        self::assertCount(1, $publishedEvents ?? []);
        self::assertInstanceOf(DocumentUploaded::class, ($publishedEvents ?? [])[0]);
    }

    public function testValidUploadCallsStorageStore(): void
    {
        $this->repository->method('get')->willReturn($this->submittedRequest);
        $this->storage->expects(self::once())
            ->method('store')
            ->with($this->id->toString(), 'binary_content', 'image/jpeg')
            ->willReturn('documents/stored.jpg');

        $this->handler->handle($this->validCommand());
    }

    public function testValidUploadSetsStoragePath(): void
    {
        $this->repository->method('get')->willReturn($this->submittedRequest);
        $this->storage->method('store')->willReturn('documents/test_path.jpg');

        $capturedAggregate = null;
        $this->repository->method('save')
            ->willReturnCallback(function (KycRequest $agg) use (&$capturedAggregate): void {
                $capturedAggregate = $agg;
            });

        $this->handler->handle($this->validCommand());

        self::assertSame('documents/test_path.jpg', $capturedAggregate?->getStoragePath());
    }

    // ── Rejet : taille ───────────────────────────────────────────────────────

    public function testFileTooLargeProducesDocumentRejectedWithE_UPLOAD_SIZE(): void
    {
        $this->repository->method('get')->willReturn($this->submittedRequest);
        $this->storage->expects(self::never())->method('store');

        $publishedEvents = null;
        $this->publisher->method('publishAll')
            ->willReturnCallback(function (array $events) use (&$publishedEvents): void {
                $publishedEvents = $events;
            });

        $command = new UploadDocument(
            kycRequestId: $this->id->toString(),
            fileContent: 'big_file',
            mimeType: 'image/jpeg',
            sizeBytes: 11 * 1024 * 1024, // 11 Mo > 10 Mo max
            dpi: 300.0,
            blurVariance: 120.0,
            sha256Hash: 'abc123',
        );

        $this->handler->handle($command);

        self::assertInstanceOf(DocumentRejectedOnUpload::class, ($publishedEvents ?? [])[0]);
        self::assertSame('E_UPLOAD_SIZE', ($publishedEvents ?? [])[0]->failureReason->code);
    }

    // ── Rejet : MIME ─────────────────────────────────────────────────────────

    public function testInvalidMimeProducesDocumentRejectedWithE_UPLOAD_MIME(): void
    {
        $this->repository->method('get')->willReturn($this->submittedRequest);
        $this->storage->expects(self::never())->method('store');

        $publishedEvents = null;
        $this->publisher->method('publishAll')
            ->willReturnCallback(function (array $events) use (&$publishedEvents): void {
                $publishedEvents = $events;
            });

        $command = new UploadDocument(
            kycRequestId: $this->id->toString(),
            fileContent: 'content',
            mimeType: 'image/gif',
            sizeBytes: 500_000,
            dpi: 300.0,
            blurVariance: 120.0,
            sha256Hash: 'abc123',
        );

        $this->handler->handle($command);

        self::assertSame('E_UPLOAD_MIME', ($publishedEvents ?? [])[0]->failureReason->code);
    }

    // ── Rejet : DPI ──────────────────────────────────────────────────────────

    public function testLowDpiProducesDocumentRejectedWithE_UPLOAD_DPI(): void
    {
        $this->repository->method('get')->willReturn($this->submittedRequest);
        $this->storage->expects(self::never())->method('store');

        $publishedEvents = null;
        $this->publisher->method('publishAll')
            ->willReturnCallback(function (array $events) use (&$publishedEvents): void {
                $publishedEvents = $events;
            });

        $command = new UploadDocument(
            kycRequestId: $this->id->toString(),
            fileContent: 'content',
            mimeType: 'image/jpeg',
            sizeBytes: 500_000,
            dpi: 200.0, // < 300
            blurVariance: 120.0,
            sha256Hash: 'abc123',
        );

        $this->handler->handle($command);

        self::assertSame('E_UPLOAD_DPI', ($publishedEvents ?? [])[0]->failureReason->code);
    }

    // ── Rejet : flou ─────────────────────────────────────────────────────────

    public function testBlurryImageProducesDocumentRejectedWithE_UPLOAD_BLUR(): void
    {
        $this->repository->method('get')->willReturn($this->submittedRequest);
        $this->storage->expects(self::never())->method('store');

        $publishedEvents = null;
        $this->publisher->method('publishAll')
            ->willReturnCallback(function (array $events) use (&$publishedEvents): void {
                $publishedEvents = $events;
            });

        $command = new UploadDocument(
            kycRequestId: $this->id->toString(),
            fileContent: 'content',
            mimeType: 'image/jpeg',
            sizeBytes: 500_000,
            dpi: 300.0,
            blurVariance: 50.0, // < 100
            sha256Hash: 'abc123',
        );

        $this->handler->handle($command);

        self::assertSame('E_UPLOAD_BLUR', ($publishedEvents ?? [])[0]->failureReason->code);
    }

    // ── Pas de stockage si rejet ──────────────────────────────────────────────

    public function testStorageNotCalledOnRejection(): void
    {
        $this->repository->method('get')->willReturn($this->submittedRequest);
        $this->storage->expects(self::never())->method('store');

        $command = new UploadDocument(
            kycRequestId: $this->id->toString(),
            fileContent: 'content',
            mimeType: 'image/bmp', // invalide
            sizeBytes: 500_000,
            dpi: 300.0,
            blurVariance: 120.0,
            sha256Hash: 'abc123',
        );

        $this->handler->handle($command);
    }
}
