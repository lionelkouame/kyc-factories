<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Projection;

use App\Domain\KycRequest\Event\DocumentRejectedOnUpload;
use App\Domain\KycRequest\Event\DocumentUploaded;
use App\Domain\KycRequest\Event\KycApproved;
use App\Domain\KycRequest\Event\KycRejected;
use App\Domain\KycRequest\Event\KycRequestSubmitted;
use App\Domain\KycRequest\Event\ManualReviewRequested;
use App\Domain\KycRequest\Event\OcrExtractionFailed;
use App\Domain\KycRequest\Event\OcrExtractionSucceeded;
use App\Domain\KycRequest\ValueObject\ApplicantId;
use App\Domain\KycRequest\ValueObject\BlurVarianceScore;
use App\Domain\KycRequest\ValueObject\DocumentType;
use App\Domain\KycRequest\ValueObject\FailureReason;
use App\Domain\KycRequest\ValueObject\KycRequestId;
use App\Domain\KycRequest\ValueObject\OcrConfidenceScore;
use App\Infrastructure\Projection\InMemoryKycRequestStatusProjector;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires de InMemoryKycRequestStatusProjector.
 */
final class InMemoryKycRequestStatusProjectorTest extends TestCase
{
    private KycRequestId $id;
    private ApplicantId $applicantId;
    private InMemoryKycRequestStatusProjector $projector;

    protected function setUp(): void
    {
        $this->id = KycRequestId::generate();
        $this->applicantId = ApplicantId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $this->projector = new InMemoryKycRequestStatusProjector();
    }

    public function testProjectKycRequestSubmittedCreatesEntry(): void
    {
        $event = new KycRequestSubmitted($this->id, $this->applicantId, DocumentType::Cni);
        $this->projector->project($event);

        $view = $this->projector->findById($this->id->toString());
        self::assertNotNull($view);
        self::assertSame('submitted', $view->status);
        self::assertSame('cni', $view->documentType);
        self::assertSame($this->applicantId->toString(), $view->applicantId);
    }

    public function testProjectDocumentUploadedUpdatesStatus(): void
    {
        $this->projector->project(new KycRequestSubmitted($this->id, $this->applicantId, DocumentType::Cni));
        $this->projector->project(new DocumentUploaded($this->id, 'path/doc.jpg', 'image/jpeg', 1024, 300.0, BlurVarianceScore::fromFloat(120.0), 'sha256'));

        $view = $this->projector->findById($this->id->toString());
        self::assertSame('document_uploaded', $view?->status);
    }

    public function testProjectDocumentRejectedOnUpload(): void
    {
        $this->projector->project(new KycRequestSubmitted($this->id, $this->applicantId, DocumentType::Cni));
        $this->projector->project(new DocumentRejectedOnUpload($this->id, new FailureReason('E_BLUR', 'Trop flou')));

        self::assertSame('document_rejected', $this->projector->findById($this->id->toString())?->status);
    }

    public function testProjectOcrExtractionSucceeded(): void
    {
        $this->projector->project(new KycRequestSubmitted($this->id, $this->applicantId, DocumentType::Cni));
        $this->projector->project(new DocumentUploaded($this->id, 'path/doc.jpg', 'image/jpeg', 1024, 300.0, BlurVarianceScore::fromFloat(120.0), 'sha256'));
        $this->projector->project(new OcrExtractionSucceeded($this->id, 'Doe', 'John', '1990-01-01', '2030-12-31', 'AB123456', 'MRZ', OcrConfidenceScore::fromFloat(95.0)));

        self::assertSame('ocr_completed', $this->projector->findById($this->id->toString())?->status);
    }

    public function testProjectOcrExtractionFailed(): void
    {
        $this->projector->project(new KycRequestSubmitted($this->id, $this->applicantId, DocumentType::Cni));
        $this->projector->project(new DocumentUploaded($this->id, 'path/doc.jpg', 'image/jpeg', 1024, 300.0, BlurVarianceScore::fromFloat(120.0), 'sha256'));
        $this->projector->project(new OcrExtractionFailed($this->id, new FailureReason('E_OCR', 'Échec OCR'), 30.0));

        self::assertSame('ocr_failed', $this->projector->findById($this->id->toString())?->status);
    }

    public function testProjectKycApproved(): void
    {
        $this->projector->project(new KycRequestSubmitted($this->id, $this->applicantId, DocumentType::Cni));
        $this->projector->project(new KycApproved($this->id));

        self::assertSame('approved', $this->projector->findById($this->id->toString())?->status);
    }

    public function testProjectKycRejected(): void
    {
        $this->projector->project(new KycRequestSubmitted($this->id, $this->applicantId, DocumentType::Cni));
        $this->projector->project(new KycRejected($this->id, [new FailureReason('E_VAL_EXPIRED', 'Document expiré')]));

        self::assertSame('rejected', $this->projector->findById($this->id->toString())?->status);
    }

    public function testProjectManualReviewRequested(): void
    {
        $this->projector->project(new KycRequestSubmitted($this->id, $this->applicantId, DocumentType::Cni));
        $this->projector->project(new KycRejected($this->id, [new FailureReason('E_VAL', 'Rejeté')]));
        $this->projector->project(new ManualReviewRequested($this->id, 'officer-1', 'Contestation'));

        self::assertSame('under_manual_review', $this->projector->findById($this->id->toString())?->status);
    }

    public function testFindByIdReturnsNullForUnknownId(): void
    {
        self::assertNull($this->projector->findById('unknown-id'));
    }

    public function testFindAllReturnsAllEntries(): void
    {
        $id2 = KycRequestId::generate();

        $this->projector->project(new KycRequestSubmitted($this->id, $this->applicantId, DocumentType::Cni));
        $this->projector->project(new KycRequestSubmitted($id2, $this->applicantId, DocumentType::Passeport));

        self::assertCount(2, $this->projector->findAll());
    }

    public function testResetClearsAllEntries(): void
    {
        $this->projector->project(new KycRequestSubmitted($this->id, $this->applicantId, DocumentType::Cni));
        $this->projector->reset();

        self::assertNull($this->projector->findById($this->id->toString()));
        self::assertCount(0, $this->projector->findAll());
    }

    public function testUnknownEventIsIgnoredSilently(): void
    {
        $event = new KycApproved(KycRequestId::generate());
        $this->projector->project($event);

        self::assertNull($this->projector->findById($event->getAggregateId()));
    }
}
