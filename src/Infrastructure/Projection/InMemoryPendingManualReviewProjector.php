<?php

declare(strict_types=1);

namespace App\Infrastructure\Projection;

use App\Application\Projection\PendingManualReviewProjectorPort;
use App\Application\Query\ReadModel\PendingManualReviewItem;
use App\Domain\KycRequest\Event\DomainEvent;
use App\Domain\KycRequest\Event\KycApproved;
use App\Domain\KycRequest\Event\KycRejected;
use App\Domain\KycRequest\Event\ManualReviewRequested;

/**
 * Implémentation in-memory de la projection des demandes en attente de révision manuelle.
 *
 * Ajoute une entrée sur ManualReviewRequested et la retire sur KycApproved/KycRejected.
 */
final class InMemoryPendingManualReviewProjector implements PendingManualReviewProjectorPort
{
    /** @var array<string, PendingManualReviewItem> */
    private array $store = [];

    public function project(DomainEvent $event): void
    {
        $aggregateId = $event->getAggregateId();

        match (true) {
            $event instanceof ManualReviewRequested => $this->store[$aggregateId] = new PendingManualReviewItem(
                kycRequestId: $aggregateId,
                applicantId: '',
                requestedBy: $event->requestedBy,
                reason: $event->reason,
                requestedAt: $event->occurredAt,
            ),

            $event instanceof KycApproved,
            $event instanceof KycRejected => $this->remove($aggregateId),

            default => null,
        };
    }

    public function reset(): void
    {
        $this->store = [];
    }

    /** @return PendingManualReviewItem[] */
    public function findAll(): array
    {
        return array_values($this->store);
    }

    private function remove(string $aggregateId): void
    {
        unset($this->store[$aggregateId]);
    }
}
