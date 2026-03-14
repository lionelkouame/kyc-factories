<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Event;

use App\Domain\KycRequest\Event\DocumentPurged;
use App\Domain\KycRequest\Event\DocumentRejectedOnUpload;
use App\Domain\KycRequest\Event\DocumentUploaded;
use App\Domain\KycRequest\Event\KycApproved;
use App\Domain\KycRequest\Event\KycRejected;
use App\Domain\KycRequest\Event\KycRequestSubmitted;
use App\Domain\KycRequest\Event\ManualReviewDecisionRecorded;
use App\Domain\KycRequest\Event\ManualReviewRequested;
use App\Domain\KycRequest\Event\OcrExtractionFailed;
use App\Domain\KycRequest\Event\OcrExtractionSucceeded;
use App\Domain\KycRequest\ValueObject\ApplicantId;
use App\Domain\KycRequest\ValueObject\BlurVarianceScore;
use App\Domain\KycRequest\ValueObject\DocumentType;
use App\Domain\KycRequest\ValueObject\FailureReason;
use App\Domain\KycRequest\ValueObject\KycRequestId;
use App\Domain\KycRequest\ValueObject\OcrConfidenceScore;
use PHPUnit\Framework\TestCase;

final class DomainEventTest extends TestCase
{
    private KycRequestId $kycRequestId;

    protected function setUp(): void
    {
        $this->kycRequestId = KycRequestId::generate();
    }

    // ── Structure commune ─────────────────────────────────────────────────────

    public function testEventHasUniqueId(): void
    {
        $e1 = new KycApproved($this->kycRequestId);
        $e2 = new KycApproved($this->kycRequestId);

        self::assertNotSame($e1->eventId, $e2->eventId);
    }

    public function testEventHasOccurredAt(): void
    {
        $event = new KycApproved($this->kycRequestId);

        self::assertInstanceOf(\DateTimeImmutable::class, $event->occurredAt);
    }

    public function testAggregateTypeIsAlwaysKycRequest(): void
    {
        $event = new KycApproved($this->kycRequestId);

        self::assertSame('KycRequest', $event->getAggregateType());
    }

    public function testToArrayContainsAllRequiredFields(): void
    {
        $event = new KycApproved($this->kycRequestId);
        $event->version = 5;

        $array = $event->toArray();

        self::assertArrayHasKey('eventId', $array);
        self::assertArrayHasKey('aggregateId', $array);
        self::assertArrayHasKey('aggregateType', $array);
        self::assertArrayHasKey('eventType', $array);
        self::assertArrayHasKey('payload', $array);
        self::assertArrayHasKey('occurredAt', $array);
        self::assertArrayHasKey('version', $array);
        self::assertSame(5, $array['version']);
        self::assertSame('KycRequest', $array['aggregateType']);
    }

    // ── KycRequestSubmitted ───────────────────────────────────────────────────

    public function testKycRequestSubmittedPayload(): void
    {
        $applicantId = ApplicantId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $event = new KycRequestSubmitted($this->kycRequestId, $applicantId, DocumentType::Cni);

        $payload = $event->getPayload();

        self::assertSame($this->kycRequestId->toString(), $payload['kycRequestId']);
        self::assertSame($applicantId->toString(), $payload['applicantId']);
        self::assertSame(DocumentType::Cni->value, $payload['documentType']);
        self::assertSame('kyc_request.submitted', $event->getEventType());
    }

    // ── DocumentUploaded ─────────────────────────────────────────────────────

    public function testDocumentUploadedPayload(): void
    {
        $event = new DocumentUploaded(
            kycRequestId: $this->kycRequestId,
            storagePath: '/var/kyc-documents/2026/03/test.jpg',
            mimeType: 'image/jpeg',
            sizeBytes: 1_024_000,
            dpi: 300.0,
            blurVariance: BlurVarianceScore::fromFloat(150.5),
            sha256Hash: hash('sha256', 'fake-content'),
        );

        /** @var array<string, mixed> $payload */
        $payload = $event->getPayload();

        self::assertSame('image/jpeg', $payload['mimeType']);
        self::assertSame(1_024_000, $payload['sizeBytes']);
        self::assertSame(300.0, $payload['dpi']);
        self::assertSame(150.5, $payload['blurVariance']);
        self::assertSame(64, \strlen(\is_string($payload['sha256Hash']) ? $payload['sha256Hash'] : ''));
        self::assertSame('kyc_request.document_uploaded', $event->getEventType());
    }

    // ── DocumentRejectedOnUpload ──────────────────────────────────────────────

    public function testDocumentRejectedOnUploadPayload(): void
    {
        $reason = new FailureReason('E_UPLOAD_BLUR', 'Image trop floue.');
        $event = new DocumentRejectedOnUpload($this->kycRequestId, $reason);

        $payload = $event->getPayload();

        self::assertSame('E_UPLOAD_BLUR', $payload['failureCode']);
        self::assertSame('Image trop floue.', $payload['failureMessage']);
        self::assertSame('kyc_request.document_rejected_on_upload', $event->getEventType());
    }

    // ── OcrExtractionSucceeded ────────────────────────────────────────────────

    public function testOcrExtractionSucceededPayload(): void
    {
        $event = new OcrExtractionSucceeded(
            kycRequestId: $this->kycRequestId,
            lastName: 'DUPONT',
            firstName: 'JEAN',
            birthDate: '1990-06-15',
            expiryDate: '2030-01-01',
            documentId: 'AB123456789',
            mrz: str_repeat('A', 30)."\n".str_repeat('B', 30),
            confidenceScore: OcrConfidenceScore::fromFloat(87.5),
        );

        $payload = $event->getPayload();

        self::assertSame('DUPONT', $payload['lastName']);
        self::assertSame('JEAN', $payload['firstName']);
        self::assertSame(87.5, $payload['confidenceScore']);
        self::assertSame('kyc_request.ocr_extraction_succeeded', $event->getEventType());
    }

    public function testOcrExtractionSucceededWithNullFields(): void
    {
        $event = new OcrExtractionSucceeded(
            kycRequestId: $this->kycRequestId,
            lastName: null,
            firstName: null,
            birthDate: null,
            expiryDate: null,
            documentId: null,
            mrz: null,
            confidenceScore: OcrConfidenceScore::fromFloat(62.0),
        );

        self::assertNull($event->getPayload()['lastName']);
        self::assertNull($event->getPayload()['mrz']);
    }

    // ── OcrExtractionFailed ───────────────────────────────────────────────────

    public function testOcrExtractionFailedWithScore(): void
    {
        $event = new OcrExtractionFailed(
            kycRequestId: $this->kycRequestId,
            failureReason: new FailureReason('E_OCR_CONFIDENCE', 'Score insuffisant.'),
            confidenceScore: 42.0,
        );

        self::assertSame(42.0, $event->getPayload()['confidenceScore']);
        self::assertSame('kyc_request.ocr_extraction_failed', $event->getEventType());
    }

    public function testOcrExtractionFailedWithoutScore(): void
    {
        $event = new OcrExtractionFailed(
            kycRequestId: $this->kycRequestId,
            failureReason: new FailureReason('E_OCR_TIMEOUT', 'Timeout Tesseract.'),
        );

        self::assertNull($event->getPayload()['confidenceScore']);
    }

    // ── KycApproved ───────────────────────────────────────────────────────────

    public function testKycApprovedPayload(): void
    {
        $event = new KycApproved($this->kycRequestId);

        self::assertSame($this->kycRequestId->toString(), $event->getPayload()['kycRequestId']);
        self::assertSame('kyc_request.approved', $event->getEventType());
    }

    // ── KycRejected ───────────────────────────────────────────────────────────

    public function testKycRejectedWithMultipleReasons(): void
    {
        $event = new KycRejected(
            kycRequestId: $this->kycRequestId,
            failureReasons: [
                new FailureReason('E_VAL_MRZ', 'MRZ invalide.'),
                new FailureReason('E_VAL_NAME', 'Nom absent.'),
            ],
        );

        /** @var array<string, mixed> $payload */
        $payload = $event->getPayload();
        /** @var array<array{code: string, message: string}> $reasons */
        $reasons = $payload['failureReasons'];

        self::assertCount(2, $reasons);
        self::assertSame('E_VAL_MRZ', $reasons[0]['code']);
        self::assertSame('E_VAL_NAME', $reasons[1]['code']);
        self::assertSame('kyc_request.rejected', $event->getEventType());
    }

    // ── ManualReviewRequested ─────────────────────────────────────────────────

    public function testManualReviewRequestedPayload(): void
    {
        $event = new ManualReviewRequested(
            kycRequestId: $this->kycRequestId,
            requestedBy: 'officer-uuid-123',
            reason: 'Document lisible malgré score bas.',
        );

        $payload = $event->getPayload();

        self::assertSame('officer-uuid-123', $payload['requestedBy']);
        self::assertSame('Document lisible malgré score bas.', $payload['reason']);
        self::assertSame('kyc_request.manual_review_requested', $event->getEventType());
    }

    // ── ManualReviewDecisionRecorded ──────────────────────────────────────────

    public function testManualReviewDecisionRecordedPayload(): void
    {
        $event = new ManualReviewDecisionRecorded(
            kycRequestId: $this->kycRequestId,
            reviewerId: 'reviewer-uuid-456',
            decision: 'approved',
            justification: 'Document authentique vérifié manuellement.',
        );

        $payload = $event->getPayload();

        self::assertSame('approved', $payload['decision']);
        self::assertSame('reviewer-uuid-456', $payload['reviewerId']);
        self::assertSame('kyc_request.manual_review_decision_recorded', $event->getEventType());
    }

    // ── DocumentPurged ────────────────────────────────────────────────────────

    public function testDocumentPurgedPayload(): void
    {
        $event = new DocumentPurged($this->kycRequestId);

        self::assertSame($this->kycRequestId->toString(), $event->getPayload()['kycRequestId']);
        self::assertSame('kyc_request.document_purged', $event->getEventType());
    }

    // ── toArray() — sérialisation sans perte ─────────────────────────────────

    public function testToArrayIsJsonSerializable(): void
    {
        $event = new KycApproved($this->kycRequestId);
        $event->version = 3;

        $json = json_encode($event->toArray());

        self::assertNotFalse($json);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $json, true);

        self::assertSame($event->eventId, $decoded['eventId']);
        self::assertSame($this->kycRequestId->toString(), $decoded['aggregateId']);
        self::assertSame(3, $decoded['version']);
    }
}
