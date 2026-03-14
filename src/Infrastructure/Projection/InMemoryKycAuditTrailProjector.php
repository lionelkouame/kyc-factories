<?php

declare(strict_types=1);

namespace App\Infrastructure\Projection;

use App\Application\Projection\KycAuditTrailProjectorPort;
use App\Application\Query\ReadModel\AuditTrailEntry;
use App\Application\Query\ReadModel\KycAuditTrailView;
use App\Domain\KycRequest\Event\DomainEvent;

/**
 * Implémentation in-memory de la projection de piste d'audit.
 *
 * Conserve la liste complète des événements pour chaque demande KYC,
 * dans l'ordre chronologique.
 */
final class InMemoryKycAuditTrailProjector implements KycAuditTrailProjectorPort
{
    /** @var array<string, AuditTrailEntry[]> */
    private array $store = [];

    public function project(DomainEvent $event): void
    {
        $aggregateId = $event->getAggregateId();

        $this->store[$aggregateId][] = new AuditTrailEntry(
            eventId: $event->eventId,
            eventType: $event->getEventType(),
            payload: $event->getPayload(),
            occurredAt: $event->occurredAt,
            version: $event->version,
        );
    }

    public function reset(): void
    {
        $this->store = [];
    }

    public function findByAggregateId(string $kycRequestId): ?KycAuditTrailView
    {
        if (!isset($this->store[$kycRequestId])) {
            return null;
        }

        return new KycAuditTrailView(
            kycRequestId: $kycRequestId,
            entries: $this->store[$kycRequestId],
        );
    }
}
