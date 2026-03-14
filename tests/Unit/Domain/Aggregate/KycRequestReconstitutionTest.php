<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Aggregate;

use App\Domain\KycRequest\Aggregate\KycRequest;
use App\Domain\KycRequest\Aggregate\KycStatus;
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
use App\Domain\KycRequest\Exception\InvalidTransitionException;
use App\Domain\KycRequest\ValueObject\ApplicantId;
use App\Domain\KycRequest\ValueObject\BlurVarianceScore;
use App\Domain\KycRequest\ValueObject\DocumentType;
use App\Domain\KycRequest\ValueObject\FailureReason;
use App\Domain\KycRequest\ValueObject\KycRequestId;
use App\Domain\KycRequest\ValueObject\OcrConfidenceScore;
use PHPUnit\Framework\TestCase;

/**
 * US-04 — Reconstitution de l'agrégat depuis l'Event Store.
 *
 * Vérifie que KycRequest::reconstitute() rejoue correctement chaque événement
 * de domaine et restaure l'état complet de l'agrégat.
 */
final class KycRequestReconstitutionTest extends TestCase
{
    private KycRequestId $id;
    private ApplicantId $applicantId;

    protected function setUp(): void
    {
        $this->id = KycRequestId::generate();
        $this->applicantId = ApplicantId::fromString('550e8400-e29b-41d4-a716-446655440000');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function submittedEvent(): KycRequestSubmitted
    {
        return new KycRequestSubmitted($this->id, $this->applicantId, DocumentType::Cni);
    }

    private function documentUploadedEvent(): DocumentUploaded
    {
        return new DocumentUploaded(
            kycRequestId: $this->id,
            storagePath: 'documents/cni_abc123.jpg',
            mimeType: 'image/jpeg',
            sizeBytes: 1_024_000,
            dpi: 300.0,
            blurVariance: BlurVarianceScore::fromFloat(120.5),
            sha256Hash: 'abc123def456',
        );
    }

    private function ocrSucceededEvent(): OcrExtractionSucceeded
    {
        return new OcrExtractionSucceeded(
            kycRequestId: $this->id,
            lastName: 'DUPONT',
            firstName: 'Jean',
            birthDate: '1990-06-15',
            expiryDate: '2030-06-14',
            documentId: 'FR123456789',
            mrz: 'IDFRADUPONT<<JEAN<<<<<<<<<<<FR123456789',
            confidenceScore: OcrConfidenceScore::fromFloat(85.0),
        );
    }

    /** @param array{version: int} $overrides */
    private function withVersion(object $event, int $version): object
    {
        $event->version = $version;

        return $event;
    }

    private function buildEventSequence(object ...$events): array
    {
        $result = [];
        foreach ($events as $i => $event) {
            $event->version = $i + 1;
            $result[] = $event;
        }

        return $result;
    }

    // ── applyKycRequestSubmitted ──────────────────────────────────────────────

    public function testReconstituteFromSubmittedRestoresIdentityAndStatus(): void
    {
        $events = $this->buildEventSequence($this->submittedEvent());

        $aggregate = KycRequest::reconstitute($events);

        self::assertSame(KycStatus::Submitted, $aggregate->getStatus());
        self::assertTrue($aggregate->getId()->equals($this->id));
        self::assertTrue($aggregate->getApplicantId()->equals($this->applicantId));
        self::assertSame(DocumentType::Cni, $aggregate->getDocumentType());
        self::assertSame(1, $aggregate->getVersion());
    }

    // ── applyDocumentUploaded ─────────────────────────────────────────────────

    public function testReconstituteFromDocumentUploadedRestoresDocumentState(): void
    {
        $events = $this->buildEventSequence(
            $this->submittedEvent(),
            $this->documentUploadedEvent(),
        );

        $aggregate = KycRequest::reconstitute($events);

        self::assertSame(KycStatus::DocumentUploaded, $aggregate->getStatus());
        self::assertSame('documents/cni_abc123.jpg', $aggregate->getStoragePath());
        self::assertSame('image/jpeg', $aggregate->getMimeType());
        self::assertSame('abc123def456', $aggregate->getSha256Hash());
        self::assertSame(2, $aggregate->getVersion());
    }

    public function testReconstituteDocumentUploadedAllowsOcrInvariant(): void
    {
        $events = $this->buildEventSequence(
            $this->submittedEvent(),
            $this->documentUploadedEvent(),
        );

        $aggregate = KycRequest::reconstitute($events);

        // Ne doit pas lever d'exception
        $aggregate->assertCanRunOcr();
        $this->addToAssertionCount(1);
    }

    // ── applyDocumentRejectedOnUpload ─────────────────────────────────────────

    public function testReconstituteFromDocumentRejectedRestoresStatus(): void
    {
        $events = $this->buildEventSequence(
            $this->submittedEvent(),
            new DocumentRejectedOnUpload(
                $this->id,
                new FailureReason('DOC_TOO_BLURRY', 'Le document est trop flou.'),
            ),
        );

        $aggregate = KycRequest::reconstitute($events);

        self::assertSame(KycStatus::DocumentRejected, $aggregate->getStatus());
        self::assertCount(1, $aggregate->getFailureReasons());
        self::assertSame('DOC_TOO_BLURRY', $aggregate->getFailureReasons()[0]->code);
    }

    // ── applyOcrExtractionSucceeded ───────────────────────────────────────────

    public function testReconstituteFromOcrSucceededRestoresOcrData(): void
    {
        $events = $this->buildEventSequence(
            $this->submittedEvent(),
            $this->documentUploadedEvent(),
            $this->ocrSucceededEvent(),
        );

        $aggregate = KycRequest::reconstitute($events);

        self::assertSame(KycStatus::OcrCompleted, $aggregate->getStatus());
        self::assertSame('DUPONT', $aggregate->getLastName());
        self::assertSame('Jean', $aggregate->getFirstName());
        self::assertSame('1990-06-15', $aggregate->getBirthDate());
        self::assertSame('2030-06-14', $aggregate->getExpiryDate());
        self::assertSame('FR123456789', $aggregate->getDocumentId());
        self::assertSame('IDFRADUPONT<<JEAN<<<<<<<<<<<FR123456789', $aggregate->getMrz());
        self::assertSame(3, $aggregate->getVersion());
    }

    public function testReconstituteOcrSucceededWithNullableFieldsReturnsNull(): void
    {
        $events = $this->buildEventSequence(
            $this->submittedEvent(),
            $this->documentUploadedEvent(),
            new OcrExtractionSucceeded(
                kycRequestId: $this->id,
                lastName: null,
                firstName: null,
                birthDate: null,
                expiryDate: null,
                documentId: null,
                mrz: null,
                confidenceScore: OcrConfidenceScore::fromFloat(62.0),
            ),
        );

        $aggregate = KycRequest::reconstitute($events);

        self::assertSame(KycStatus::OcrCompleted, $aggregate->getStatus());
        self::assertNull($aggregate->getLastName());
        self::assertNull($aggregate->getFirstName());
        self::assertNull($aggregate->getMrz());
    }

    public function testReconstituteOcrSucceededAllowsValidationInvariant(): void
    {
        $events = $this->buildEventSequence(
            $this->submittedEvent(),
            $this->documentUploadedEvent(),
            $this->ocrSucceededEvent(),
        );

        $aggregate = KycRequest::reconstitute($events);

        $aggregate->assertCanValidate();
        $this->addToAssertionCount(1);
    }

    // ── applyOcrExtractionFailed ──────────────────────────────────────────────

    public function testReconstituteFromOcrFailedRestoresStatus(): void
    {
        $events = $this->buildEventSequence(
            $this->submittedEvent(),
            $this->documentUploadedEvent(),
            new OcrExtractionFailed(
                $this->id,
                new FailureReason('OCR_LOW_CONFIDENCE', 'Score de confiance insuffisant.'),
                45.0,
            ),
        );

        $aggregate = KycRequest::reconstitute($events);

        self::assertSame(KycStatus::OcrFailed, $aggregate->getStatus());
        self::assertCount(1, $aggregate->getFailureReasons());
        self::assertSame('OCR_LOW_CONFIDENCE', $aggregate->getFailureReasons()[0]->code);
    }

    public function testReconstituteOcrFailedAllowsManualReviewInvariant(): void
    {
        $events = $this->buildEventSequence(
            $this->submittedEvent(),
            $this->documentUploadedEvent(),
            new OcrExtractionFailed(
                $this->id,
                new FailureReason('OCR_ERROR', 'Erreur OCR.'),
            ),
        );

        $aggregate = KycRequest::reconstitute($events);

        $aggregate->assertCanRequestManualReview();
        $this->addToAssertionCount(1);
    }

    // ── applyKycApproved ──────────────────────────────────────────────────────

    public function testReconstituteFromKycApprovedRestoresStatus(): void
    {
        $events = $this->buildEventSequence(
            $this->submittedEvent(),
            $this->documentUploadedEvent(),
            $this->ocrSucceededEvent(),
            new KycApproved($this->id),
        );

        $aggregate = KycRequest::reconstitute($events);

        self::assertSame(KycStatus::Approved, $aggregate->getStatus());
        self::assertSame(4, $aggregate->getVersion());
    }

    public function testReconstituteApprovedBlocksFinallyDecidedInvariant(): void
    {
        $this->expectException(InvalidTransitionException::class);

        $events = $this->buildEventSequence(
            $this->submittedEvent(),
            $this->documentUploadedEvent(),
            $this->ocrSucceededEvent(),
            new KycApproved($this->id),
        );

        $aggregate = KycRequest::reconstitute($events);
        $aggregate->assertIsNotFinallyDecided();
    }

    // ── applyKycRejected ──────────────────────────────────────────────────────

    public function testReconstituteFromKycRejectedRestoresStatusAndReasons(): void
    {
        $reasons = [
            new FailureReason('DOC_EXPIRED', 'Document expiré.'),
            new FailureReason('IDENTITY_MISMATCH', 'Identité non conforme.'),
        ];

        $events = $this->buildEventSequence(
            $this->submittedEvent(),
            $this->documentUploadedEvent(),
            $this->ocrSucceededEvent(),
            new KycRejected($this->id, $reasons),
        );

        $aggregate = KycRequest::reconstitute($events);

        self::assertSame(KycStatus::Rejected, $aggregate->getStatus());
        self::assertCount(2, $aggregate->getFailureReasons());
        self::assertSame('DOC_EXPIRED', $aggregate->getFailureReasons()[0]->code);
        self::assertSame('IDENTITY_MISMATCH', $aggregate->getFailureReasons()[1]->code);
    }

    public function testReconstituteRejectedAllowsManualReviewInvariant(): void
    {
        $events = $this->buildEventSequence(
            $this->submittedEvent(),
            $this->documentUploadedEvent(),
            $this->ocrSucceededEvent(),
            new KycRejected($this->id, [new FailureReason('DOC_EXPIRED', 'Expiré.')]),
        );

        $aggregate = KycRequest::reconstitute($events);

        $aggregate->assertCanRequestManualReview();
        $this->addToAssertionCount(1);
    }

    // ── applyManualReviewRequested ────────────────────────────────────────────

    public function testReconstituteFromManualReviewRequestedRestoresStatus(): void
    {
        $events = $this->buildEventSequence(
            $this->submittedEvent(),
            $this->documentUploadedEvent(),
            $this->ocrSucceededEvent(),
            new KycRejected($this->id, [new FailureReason('DOC_EXPIRED', 'Expiré.')]),
            new ManualReviewRequested($this->id, 'agent@kyc.fr', 'Demande de vérification manuelle.'),
        );

        $aggregate = KycRequest::reconstitute($events);

        self::assertSame(KycStatus::UnderManualReview, $aggregate->getStatus());
        self::assertSame(5, $aggregate->getVersion());
    }

    // ── applyManualReviewDecisionRecorded ─────────────────────────────────────

    public function testReconstituteManualReviewApprovedRestoresApprovedStatus(): void
    {
        $events = $this->buildEventSequence(
            $this->submittedEvent(),
            $this->documentUploadedEvent(),
            $this->ocrSucceededEvent(),
            new KycRejected($this->id, [new FailureReason('DOC_EXPIRED', 'Expiré.')]),
            new ManualReviewRequested($this->id, 'agent@kyc.fr', 'Vérification.'),
            new ManualReviewDecisionRecorded($this->id, 'reviewer-001', 'approved', 'Document validé après vérification.'),
        );

        $aggregate = KycRequest::reconstitute($events);

        self::assertSame(KycStatus::Approved, $aggregate->getStatus());
        self::assertSame(6, $aggregate->getVersion());
    }

    public function testReconstituteManualReviewRejectedRestoresRejectedStatus(): void
    {
        $events = $this->buildEventSequence(
            $this->submittedEvent(),
            $this->documentUploadedEvent(),
            $this->ocrSucceededEvent(),
            new KycRejected($this->id, [new FailureReason('DOC_EXPIRED', 'Expiré.')]),
            new ManualReviewRequested($this->id, 'agent@kyc.fr', 'Vérification.'),
            new ManualReviewDecisionRecorded($this->id, 'reviewer-001', 'rejected', 'Rejet confirmé.'),
        );

        $aggregate = KycRequest::reconstitute($events);

        self::assertSame(KycStatus::Rejected, $aggregate->getStatus());
    }

    // ── applyDocumentPurged ───────────────────────────────────────────────────

    public function testReconstituteFromDocumentPurgedClearsStoragePath(): void
    {
        $events = $this->buildEventSequence(
            $this->submittedEvent(),
            $this->documentUploadedEvent(),
            $this->ocrSucceededEvent(),
            new KycApproved($this->id),
            new DocumentPurged($this->id),
        );

        $aggregate = KycRequest::reconstitute($events);

        self::assertTrue($aggregate->isDocumentPurged());
        self::assertNull($aggregate->getStoragePath());
        self::assertSame(KycStatus::Approved, $aggregate->getStatus());
    }

    // ── État initial après reconstitution ne pollue pas les nouveaux événements

    public function testReconstituteDoesNotRecordEvents(): void
    {
        $events = $this->buildEventSequence(
            $this->submittedEvent(),
            $this->documentUploadedEvent(),
        );

        $aggregate = KycRequest::reconstitute($events);

        self::assertEmpty($aggregate->releaseEvents());
    }

    // ── Parcours complet (happy path) ─────────────────────────────────────────

    public function testFullHappyPathReconstitution(): void
    {
        $events = $this->buildEventSequence(
            $this->submittedEvent(),
            $this->documentUploadedEvent(),
            $this->ocrSucceededEvent(),
            new KycApproved($this->id),
        );

        $aggregate = KycRequest::reconstitute($events);

        self::assertSame(KycStatus::Approved, $aggregate->getStatus());
        self::assertTrue($aggregate->getId()->equals($this->id));
        self::assertSame('documents/cni_abc123.jpg', $aggregate->getStoragePath());
        self::assertSame('DUPONT', $aggregate->getLastName());
        self::assertSame('Jean', $aggregate->getFirstName());
        self::assertSame(4, $aggregate->getVersion());
        self::assertFalse($aggregate->isDocumentPurged());
        self::assertEmpty($aggregate->getFailureReasons());
    }

    // ── Parcours manuel complet ───────────────────────────────────────────────

    public function testFullManualReviewApprovedPathReconstitution(): void
    {
        $events = $this->buildEventSequence(
            $this->submittedEvent(),
            $this->documentUploadedEvent(),
            $this->ocrSucceededEvent(),
            new KycRejected($this->id, [new FailureReason('DOC_EXPIRED', 'Expiré.')]),
            new ManualReviewRequested($this->id, 'agent@kyc.fr', 'Exception.'),
            new ManualReviewDecisionRecorded($this->id, 'reviewer-001', 'approved', 'OK après vérif.'),
        );

        $aggregate = KycRequest::reconstitute($events);

        self::assertSame(KycStatus::Approved, $aggregate->getStatus());
        self::assertSame(6, $aggregate->getVersion());
        self::assertEmpty($aggregate->releaseEvents());
    }
}
