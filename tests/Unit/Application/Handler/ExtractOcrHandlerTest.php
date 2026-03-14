<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Handler;

use App\Application\Command\ExtractOcr;
use App\Application\Handler\ExtractOcrHandler;
use App\Application\Repository\KycRequestRepositoryPort;
use App\Domain\KycRequest\Aggregate\KycRequest;
use App\Domain\KycRequest\Aggregate\KycStatus;
use App\Domain\KycRequest\Event\OcrExtractionFailed;
use App\Domain\KycRequest\Event\OcrExtractionSucceeded;
use App\Domain\KycRequest\Port\DocumentStoragePort;
use App\Domain\KycRequest\Port\DomainEventPublisherPort;
use App\Domain\KycRequest\Port\OcrExtractionException;
use App\Domain\KycRequest\Port\OcrExtractionResult;
use App\Domain\KycRequest\Port\OcrPort;
use App\Domain\KycRequest\ValueObject\ApplicantId;
use App\Domain\KycRequest\ValueObject\BlurVarianceScore;
use App\Domain\KycRequest\ValueObject\DocumentType;
use App\Domain\KycRequest\ValueObject\KycRequestId;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ExtractOcrHandlerTest extends TestCase
{
    private KycRequestRepositoryPort&MockObject $repository;
    private DocumentStoragePort&MockObject $storage;
    private OcrPort&MockObject $ocr;
    private DomainEventPublisherPort&MockObject $publisher;
    private ExtractOcrHandler $handler;

    private KycRequestId $id;
    private KycRequest $documentUploadedRequest;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(KycRequestRepositoryPort::class);
        $this->storage = $this->createMock(DocumentStoragePort::class);
        $this->ocr = $this->createMock(OcrPort::class);
        $this->publisher = $this->createMock(DomainEventPublisherPort::class);
        $this->handler = new ExtractOcrHandler($this->repository, $this->storage, $this->ocr, $this->publisher);

        $this->id = KycRequestId::generate();

        // Crée un agrégat en état DocumentUploaded
        $events = [];
        $submitted = new \App\Domain\KycRequest\Event\KycRequestSubmitted(
            $this->id,
            ApplicantId::fromString('550e8400-e29b-41d4-a716-446655440000'),
            DocumentType::Cni,
        );
        $submitted->version = 1;
        $uploaded = new \App\Domain\KycRequest\Event\DocumentUploaded(
            $this->id,
            'docs/test.jpg',
            'image/jpeg',
            1_000_000,
            300.0,
            BlurVarianceScore::fromFloat(120.0),
            'sha256abc',
        );
        $uploaded->version = 2;
        $this->documentUploadedRequest = KycRequest::reconstitute([$submitted, $uploaded]);
    }

    private function goodOcrResult(): OcrExtractionResult
    {
        return new OcrExtractionResult(
            lastName: 'DUPONT',
            firstName: 'Jean',
            birthDate: '1990-06-15',
            expiryDate: '2030-06-14',
            documentId: 'FR123456789',
            mrz: 'IDFRADUPONT<<JEAN<<<<<<<<<<<FR123456789',
            confidenceScore: 85.0,
        );
    }

    // ── OCR réussi ───────────────────────────────────────────────────────────

    public function testOcrSuccessProducesOcrExtractionSucceededEvent(): void
    {
        $this->repository->method('get')->willReturn($this->documentUploadedRequest);
        $this->storage->method('retrieve')->willReturn('file_binary');
        $this->ocr->method('extract')->willReturn($this->goodOcrResult());

        $publishedEvents = null;
        $this->publisher->method('publishAll')
            ->willReturnCallback(function (array $events) use (&$publishedEvents): void {
                $publishedEvents = $events;
            });

        $this->handler->handle(new ExtractOcr($this->id->toString()));

        self::assertCount(1, $publishedEvents ?? []);
        self::assertInstanceOf(OcrExtractionSucceeded::class, ($publishedEvents ?? [])[0]);
    }

    public function testOcrSuccessRestoresOcrData(): void
    {
        $this->repository->method('get')->willReturn($this->documentUploadedRequest);
        $this->storage->method('retrieve')->willReturn('file_binary');
        $this->ocr->method('extract')->willReturn($this->goodOcrResult());

        $capturedAggregate = null;
        $this->repository->method('save')
            ->willReturnCallback(function (KycRequest $agg) use (&$capturedAggregate): void {
                $capturedAggregate = $agg;
            });

        $this->handler->handle(new ExtractOcr($this->id->toString()));

        self::assertSame(KycStatus::OcrCompleted, $capturedAggregate?->getStatus());
        self::assertSame('DUPONT', $capturedAggregate?->getLastName());
        self::assertSame('Jean', $capturedAggregate?->getFirstName());
    }

    // ── OCR score insuffisant ────────────────────────────────────────────────

    public function testLowConfidenceProducesOcrExtractionFailedWithE_OCR_CONFIDENCE(): void
    {
        $this->repository->method('get')->willReturn($this->documentUploadedRequest);
        $this->storage->method('retrieve')->willReturn('file_binary');
        $this->ocr->method('extract')->willReturn(new OcrExtractionResult(
            lastName: null,
            firstName: null,
            birthDate: null,
            expiryDate: null,
            documentId: null,
            mrz: null,
            confidenceScore: 45.0, // < 60 %
        ));

        $publishedEvents = null;
        $this->publisher->method('publishAll')
            ->willReturnCallback(function (array $events) use (&$publishedEvents): void {
                $publishedEvents = $events;
            });

        $this->handler->handle(new ExtractOcr($this->id->toString()));

        self::assertInstanceOf(OcrExtractionFailed::class, ($publishedEvents ?? [])[0]);
        self::assertSame('E_OCR_CONFIDENCE', ($publishedEvents ?? [])[0]->failureReason->code);
    }

    public function testOcrExactlyAtThresholdSucceeds(): void
    {
        $this->repository->method('get')->willReturn($this->documentUploadedRequest);
        $this->storage->method('retrieve')->willReturn('file_binary');
        $this->ocr->method('extract')->willReturn(new OcrExtractionResult(
            lastName: 'MARTIN',
            firstName: 'Claire',
            birthDate: '1985-03-01',
            expiryDate: '2028-03-01',
            documentId: 'AB123456789',
            mrz: 'IDFRAMARTIN<<CLAIRE<<<<<<<<<<AB123456789',
            confidenceScore: 60.0, // exactement 60 — acceptable
        ));

        $publishedEvents = null;
        $this->publisher->method('publishAll')
            ->willReturnCallback(function (array $events) use (&$publishedEvents): void {
                $publishedEvents = $events;
            });

        $this->handler->handle(new ExtractOcr($this->id->toString()));

        self::assertInstanceOf(OcrExtractionSucceeded::class, ($publishedEvents ?? [])[0]);
    }

    // ── OCR timeout ──────────────────────────────────────────────────────────

    public function testOcrTimeoutProducesOcrExtractionFailedWithE_OCR_TIMEOUT(): void
    {
        $this->repository->method('get')->willReturn($this->documentUploadedRequest);
        $this->storage->method('retrieve')->willReturn('file_binary');
        $this->ocr->method('extract')->willThrowException(
            new OcrExtractionException('E_OCR_TIMEOUT', 'Tesseract a dépassé le délai de 30 secondes.'),
        );

        $publishedEvents = null;
        $this->publisher->method('publishAll')
            ->willReturnCallback(function (array $events) use (&$publishedEvents): void {
                $publishedEvents = $events;
            });

        $this->handler->handle(new ExtractOcr($this->id->toString()));

        self::assertInstanceOf(OcrExtractionFailed::class, ($publishedEvents ?? [])[0]);
        self::assertSame('E_OCR_TIMEOUT', ($publishedEvents ?? [])[0]->failureReason->code);
    }

    // ── Fichier corrompu ─────────────────────────────────────────────────────

    public function testCorruptDocumentProducesOcrExtractionFailedWithE_OCR_CORRUPT(): void
    {
        $this->repository->method('get')->willReturn($this->documentUploadedRequest);
        $this->storage->method('retrieve')->willReturn('corrupt_binary');
        $this->ocr->method('extract')->willThrowException(
            new OcrExtractionException('E_OCR_CORRUPT', 'Le fichier est illisible ou corrompu.'),
        );

        $publishedEvents = null;
        $this->publisher->method('publishAll')
            ->willReturnCallback(function (array $events) use (&$publishedEvents): void {
                $publishedEvents = $events;
            });

        $this->handler->handle(new ExtractOcr($this->id->toString()));

        self::assertInstanceOf(OcrExtractionFailed::class, ($publishedEvents ?? [])[0]);
        self::assertSame('E_OCR_CORRUPT', ($publishedEvents ?? [])[0]->failureReason->code);
    }
}
