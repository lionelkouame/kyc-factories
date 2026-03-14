<?php

declare(strict_types=1);

namespace App\Domain\KycRequest\Aggregate;

use App\Domain\KycRequest\Event\DocumentPurged;
use App\Domain\KycRequest\Event\DocumentRejectedOnUpload;
use App\Domain\KycRequest\Event\DocumentUploaded;
use App\Domain\KycRequest\Event\DomainEvent;
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

/**
 * Agrégat racine du domaine KYC.
 *
 * L'état est exclusivement reconstitué par rejeu des événements de domaine.
 * Aucune propriété n'est mutée directement — seule la méthode record() est autorisée.
 */
final class KycRequest extends AggregateRoot
{
    private KycRequestId $id;
    private ApplicantId $applicantId;
    private DocumentType $documentType;
    private KycStatus $status;

    // État document
    private ?string $storagePath = null;
    private ?string $mimeType = null;
    private ?string $sha256Hash = null;
    private bool $documentPurged = false;

    // État OCR
    private ?string $lastName = null;
    private ?string $firstName = null;
    private ?string $birthDate = null;
    private ?string $expiryDate = null;
    private ?string $documentId = null;
    private ?string $mrz = null;

    /** @var FailureReason[] */
    private array $failureReasons = [];

    private function __construct()
    {
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Commandes (factory + transitions)
    // ──────────────────────────────────────────────────────────────────────────

    public static function submit(
        KycRequestId $id,
        ApplicantId $applicantId,
        DocumentType $documentType,
    ): self {
        $request = new self();
        $request->record(new KycRequestSubmitted($id, $applicantId, $documentType));

        return $request;
    }

    /**
     * Reconstitue l'agrégat depuis sa séquence d'événements (event store).
     *
     * @param DomainEvent[] $events
     */
    public static function reconstitute(array $events): self
    {
        if ([] === $events) {
            throw new \LogicException('Cannot reconstitute KycRequest from an empty event list.');
        }

        $request = new self();
        self::reconstituteFromHistory($request, ...$events);

        return $request;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Commandes métier (transitions d'état)
    // ──────────────────────────────────────────────────────────────────────────

    public function uploadDocument(
        string $storagePath,
        string $mimeType,
        int $sizeBytes,
        float $dpi,
        BlurVarianceScore $blurVariance,
        string $sha256Hash,
    ): void {
        $this->assertCanUploadDocument();
        $this->record(new DocumentUploaded($this->id, $storagePath, $mimeType, $sizeBytes, $dpi, $blurVariance, $sha256Hash));
    }

    public function rejectDocumentOnUpload(FailureReason $reason): void
    {
        $this->assertCanUploadDocument();
        $this->record(new DocumentRejectedOnUpload($this->id, $reason));
    }

    public function recordOcrSuccess(
        ?string $lastName,
        ?string $firstName,
        ?string $birthDate,
        ?string $expiryDate,
        ?string $documentId,
        ?string $mrz,
        OcrConfidenceScore $confidenceScore,
    ): void {
        $this->assertCanRunOcr();
        $this->record(new OcrExtractionSucceeded($this->id, $lastName, $firstName, $birthDate, $expiryDate, $documentId, $mrz, $confidenceScore));
    }

    public function recordOcrFailure(FailureReason $reason, ?float $confidenceScore = null): void
    {
        $this->assertCanRunOcr();
        $this->record(new OcrExtractionFailed($this->id, $reason, $confidenceScore));
    }

    public function approve(): void
    {
        $this->assertCanValidate();
        $this->record(new KycApproved($this->id));
    }

    /** @param FailureReason[] $reasons */
    public function reject(array $reasons): void
    {
        $this->assertCanValidate();
        $this->record(new KycRejected($this->id, $reasons));
    }

    public function requestManualReview(string $requestedBy, string $reason): void
    {
        $this->assertCanRequestManualReview();
        $this->record(new ManualReviewRequested($this->id, $requestedBy, $reason));
    }

    public function recordManualReviewDecision(string $reviewerId, string $decision, string $justification): void
    {
        if (KycStatus::UnderManualReview !== $this->status) {
            throw new InvalidTransitionException(\sprintf(
                'Cannot record manual review decision: current status is "%s", expected "%s".',
                $this->status->value,
                KycStatus::UnderManualReview->value,
            ));
        }

        if (!\in_array($decision, ['approved', 'rejected'], true)) {
            throw new \InvalidArgumentException(\sprintf('Invalid manual review decision "%s". Must be "approved" or "rejected".', $decision));
        }

        $this->record(new ManualReviewDecisionRecorded($this->id, $reviewerId, $decision, $justification));

        if ('approved' === $decision) {
            $this->record(new KycApproved($this->id));
        } else {
            $this->record(new KycRejected($this->id, [new FailureReason('MANUAL_REVIEW_REJECTED', $justification)]));
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Invariants (guards)
    // ──────────────────────────────────────────────────────────────────────────

    public function assertCanUploadDocument(): void
    {
        if (KycStatus::Submitted !== $this->status) {
            throw new InvalidTransitionException(\sprintf('Cannot upload document: current status is "%s", expected "%s".', $this->status->value, KycStatus::Submitted->value));
        }
    }

    public function assertCanRunOcr(): void
    {
        if (KycStatus::DocumentUploaded !== $this->status) {
            throw new InvalidTransitionException(\sprintf('Cannot run OCR: current status is "%s", expected "%s".', $this->status->value, KycStatus::DocumentUploaded->value));
        }
    }

    public function assertCanValidate(): void
    {
        if (KycStatus::OcrCompleted !== $this->status) {
            throw new InvalidTransitionException(\sprintf('Cannot validate: current status is "%s", expected "%s".', $this->status->value, KycStatus::OcrCompleted->value));
        }
    }

    public function assertCanRequestManualReview(): void
    {
        $allowed = [KycStatus::Rejected, KycStatus::OcrFailed];

        if (!\in_array($this->status, $allowed, true)) {
            throw new InvalidTransitionException(\sprintf('Cannot request manual review from status "%s". Allowed: %s.', $this->status->value, implode(', ', array_map(static fn (KycStatus $s) => $s->value, $allowed))));
        }
    }

    public function assertIsNotFinallyDecided(): void
    {
        $finals = [KycStatus::Approved, KycStatus::Rejected];

        if (\in_array($this->status, $finals, true)) {
            throw new InvalidTransitionException(\sprintf('KycRequest is in final state "%s" and cannot be modified without manual review.', $this->status->value));
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Applicateurs d'événements (reconstitution d'état)
    // ──────────────────────────────────────────────────────────────────────────

    protected function applyKycRequestSubmitted(KycRequestSubmitted $event): void
    {
        $this->id = $event->kycRequestId;
        $this->applicantId = $event->applicantId;
        $this->documentType = $event->documentType;
        $this->status = KycStatus::Submitted;
    }

    protected function applyDocumentUploaded(DocumentUploaded $event): void
    {
        $this->storagePath = $event->storagePath;
        $this->mimeType = $event->mimeType;
        $this->sha256Hash = $event->sha256Hash;
        $this->status = KycStatus::DocumentUploaded;
    }

    protected function applyDocumentRejectedOnUpload(DocumentRejectedOnUpload $event): void
    {
        $this->failureReasons = [$event->failureReason];
        $this->status = KycStatus::DocumentRejected;
    }

    protected function applyOcrExtractionSucceeded(OcrExtractionSucceeded $event): void
    {
        $this->lastName = $event->lastName;
        $this->firstName = $event->firstName;
        $this->birthDate = $event->birthDate;
        $this->expiryDate = $event->expiryDate;
        $this->documentId = $event->documentId;
        $this->mrz = $event->mrz;
        $this->status = KycStatus::OcrCompleted;
    }

    protected function applyOcrExtractionFailed(OcrExtractionFailed $event): void
    {
        $this->failureReasons = [$event->failureReason];
        $this->status = KycStatus::OcrFailed;
    }

    protected function applyKycApproved(KycApproved $event): void
    {
        $this->status = KycStatus::Approved;
    }

    protected function applyKycRejected(KycRejected $event): void
    {
        $this->failureReasons = $event->failureReasons;
        $this->status = KycStatus::Rejected;
    }

    protected function applyManualReviewRequested(ManualReviewRequested $event): void
    {
        $this->status = KycStatus::UnderManualReview;
    }

    protected function applyManualReviewDecisionRecorded(ManualReviewDecisionRecorded $event): void
    {
        $this->status = match ($event->decision) {
            'approved' => KycStatus::Approved,
            'rejected' => KycStatus::Rejected,
            default => throw new \LogicException(\sprintf('Unknown manual review decision "%s".', $event->decision)),
        };
    }

    protected function applyDocumentPurged(DocumentPurged $event): void
    {
        $this->documentPurged = true;
        $this->storagePath = null;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Accesseurs (lecture seule)
    // ──────────────────────────────────────────────────────────────────────────

    public function getAggregateId(): string
    {
        return $this->id->toString();
    }

    public function getId(): KycRequestId
    {
        return $this->id;
    }

    public function getApplicantId(): ApplicantId
    {
        return $this->applicantId;
    }

    public function getDocumentType(): DocumentType
    {
        return $this->documentType;
    }

    public function getStatus(): KycStatus
    {
        return $this->status;
    }

    public function getStoragePath(): ?string
    {
        return $this->storagePath;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function getSha256Hash(): ?string
    {
        return $this->sha256Hash;
    }

    public function isDocumentPurged(): bool
    {
        return $this->documentPurged;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function getBirthDate(): ?string
    {
        return $this->birthDate;
    }

    public function getExpiryDate(): ?string
    {
        return $this->expiryDate;
    }

    public function getDocumentId(): ?string
    {
        return $this->documentId;
    }

    public function getMrz(): ?string
    {
        return $this->mrz;
    }

    /** @return FailureReason[] */
    public function getFailureReasons(): array
    {
        return $this->failureReasons;
    }
}
