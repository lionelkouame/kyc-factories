<?php

declare(strict_types=1);

namespace App\Domain\KycRequest\Aggregate;

use App\Domain\KycRequest\Event\DomainEvent;
use App\Domain\KycRequest\Event\KycRequestSubmitted;
use App\Domain\KycRequest\Exception\InvalidTransitionException;
use App\Domain\KycRequest\ValueObject\ApplicantId;
use App\Domain\KycRequest\ValueObject\DocumentType;
use App\Domain\KycRequest\ValueObject\KycRequestId;

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

    private function __construct() {}

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
        if ($events === []) {
            throw new \LogicException('Cannot reconstitute KycRequest from an empty event list.');
        }

        $request = new self();
        self::reconstituteFromHistory($request, ...$events);

        return $request;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Invariants (guards)
    // ──────────────────────────────────────────────────────────────────────────

    public function assertCanUploadDocument(): void
    {
        if ($this->status !== KycStatus::Submitted) {
            throw new InvalidTransitionException(
                sprintf(
                    'Cannot upload document: current status is "%s", expected "%s".',
                    $this->status->value,
                    KycStatus::Submitted->value,
                )
            );
        }
    }

    public function assertCanRunOcr(): void
    {
        if ($this->status !== KycStatus::DocumentUploaded) {
            throw new InvalidTransitionException(
                sprintf(
                    'Cannot run OCR: current status is "%s", expected "%s".',
                    $this->status->value,
                    KycStatus::DocumentUploaded->value,
                )
            );
        }
    }

    public function assertCanValidate(): void
    {
        if ($this->status !== KycStatus::OcrCompleted) {
            throw new InvalidTransitionException(
                sprintf(
                    'Cannot validate: current status is "%s", expected "%s".',
                    $this->status->value,
                    KycStatus::OcrCompleted->value,
                )
            );
        }
    }

    public function assertCanRequestManualReview(): void
    {
        $allowed = [KycStatus::Rejected, KycStatus::OcrFailed];

        if (!in_array($this->status, $allowed, true)) {
            throw new InvalidTransitionException(
                sprintf(
                    'Cannot request manual review from status "%s". Allowed: %s.',
                    $this->status->value,
                    implode(', ', array_map(fn(KycStatus $s) => $s->value, $allowed)),
                )
            );
        }
    }

    public function assertIsNotFinallyDecided(): void
    {
        $finals = [KycStatus::Approved, KycStatus::Rejected];

        if (in_array($this->status, $finals, true)) {
            throw new InvalidTransitionException(
                sprintf(
                    'KycRequest is in final state "%s" and cannot be modified without manual review.',
                    $this->status->value,
                )
            );
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

    // ──────────────────────────────────────────────────────────────────────────
    // Accesseurs (lecture seule)
    // ──────────────────────────────────────────────────────────────────────────

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
}
