<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Domain\KycRequest\Event\DomainEvent;
use App\Domain\KycRequest\Port\DomainEventPublisherPort;

/**
 * Adaptateur in-memory du bus de publication d'événements.
 *
 * Stocke les événements publiés dans un tableau pour inspection dans les tests.
 */
final class InMemoryDomainEventPublisher implements DomainEventPublisherPort
{
    /** @var DomainEvent[] */
    private array $published = [];

    /** @param DomainEvent[] $events */
    public function publishAll(array $events): void
    {
        foreach ($events as $event) {
            $this->published[] = $event;
        }
    }

    /** @return DomainEvent[] */
    public function getPublishedEvents(): array
    {
        return $this->published;
    }

    public function reset(): void
    {
        $this->published = [];
    }
}
