<?php

declare(strict_types=1);

namespace App\Infrastructure\Projection;

use App\Application\Projection\KycRequestStatusProjectorPort;
use App\Application\Query\ReadModel\KycRequestStatusView;
use App\Domain\KycRequest\Event\DomainEvent;
use App\Domain\KycRequest\Event\DocumentRejectedOnUpload;
use App\Domain\KycRequest\Event\DocumentUploaded;
use App\Domain\KycRequest\Event\KycApproved;
use App\Domain\KycRequest\Event\KycRejected;
use App\Domain\KycRequest\Event\KycRequestSubmitted;
use App\Domain\KycRequest\Event\ManualReviewDecisionRecorded;
use App\Domain\KycRequest\Event\ManualReviewRequested;
use App\Domain\KycRequest\Event\OcrExtractionFailed;
use App\Domain\KycRequest\Event\OcrExtractionSucceeded;

/**
 * Implémentation in-memory de la projection d'état courant des demandes KYC.
 *
 * Maintient une table de hachage kycRequestId → KycRequestStatusView.
 * Idéal pour les tests et les environnements sans base de données.
 */
final class InMemoryKycRequestStatusProjector implements KycRequestStatusProjectorPort
{
    /** @var array<string, KycRequestStatusView> */
    private array $store = [];

    public function project(DomainEvent $event): void
    {
        $aggregateId = $event->getAggregateId();

        match (true) {
            $event instanceof KycRequestSubmitted => $this->store[$aggregateId] = new KycRequestStatusView(
                kycRequestId: $aggregateId,
                applicantId: $event->applicantId->toString(),
                documentType: $event->documentType->value,
                status: 'submitted',
                updatedAt: $event->occurredAt,
            ),

            $event instanceof DocumentUploaded => $this->update($aggregateId, 'document_uploaded', $event->occurredAt),
            $event instanceof DocumentRejectedOnUpload => $this->update($aggregateId, 'document_rejected', $event->occurredAt),
            $event instanceof OcrExtractionSucceeded => $this->update($aggregateId, 'ocr_completed', $event->occurredAt),
            $event instanceof OcrExtractionFailed => $this->update($aggregateId, 'ocr_failed', $event->occurredAt),
            $event instanceof KycApproved => $this->update($aggregateId, 'approved', $event->occurredAt),
            $event instanceof KycRejected => $this->update($aggregateId, 'rejected', $event->occurredAt),
            $event instanceof ManualReviewRequested => $this->update($aggregateId, 'under_manual_review', $event->occurredAt),
            $event instanceof ManualReviewDecisionRecorded => null,

            default => null,
        };
    }

    public function reset(): void
    {
        $this->store = [];
    }

    public function findById(string $kycRequestId): ?KycRequestStatusView
    {
        return $this->store[$kycRequestId] ?? null;
    }

    /** @return KycRequestStatusView[] */
    public function findAll(): array
    {
        return array_values($this->store);
    }

    /** @return KycRequestStatusView[] */
    public function findTerminalOlderThan(\DateTimeImmutable $before): array
    {
        $terminals = ['approved', 'rejected'];

        return array_values(array_filter(
            $this->store,
            static fn (KycRequestStatusView $v) => \in_array($v->status, $terminals, true)
                && $v->updatedAt < $before,
        ));
    }

    private function update(string $aggregateId, string $status, \DateTimeImmutable $updatedAt): void
    {
        $existing = $this->store[$aggregateId] ?? null;
        if ($existing === null) {
            return;
        }

        $this->store[$aggregateId] = new KycRequestStatusView(
            kycRequestId: $existing->kycRequestId,
            applicantId: $existing->applicantId,
            documentType: $existing->documentType,
            status: $status,
            updatedAt: $updatedAt,
        );
    }
}
