<?php

declare(strict_types=1);

namespace App\Domain\KycRequest\Aggregate;

use App\Domain\KycRequest\Event\DomainEvent;

abstract class AggregateRoot
{
    /** @var DomainEvent[] */
    private array $recordedEvents = [];
    private int $version = 0;

    protected function record(DomainEvent $event): void
    {
        ++$this->version;
        $event->version = $this->version;
        $this->applyEvent($event);
        $this->recordedEvents[] = $event;
    }

    private function applyEvent(DomainEvent $event): void
    {
        $parts = explode('\\', $event::class);
        $method = 'apply'.end($parts);

        if (method_exists($this, $method)) {
            $this->$method($event);
        }
    }

    /**
     * Rejoue les événements pour reconstituer l'état de l'agrégat.
     * Utilisé exclusivement par le repository lors du chargement depuis l'event store.
     */
    protected static function reconstituteFromHistory(self $aggregate, DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $aggregate->applyEvent($event);
            $aggregate->version = $event->version;
        }
    }

    /**
     * Retourne et vide les événements enregistrés (à persister dans l'event store).
     *
     * @return DomainEvent[]
     */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }

    /**
     * Retourne les événements enregistrés sans les vider.
     * Utile pour capturer les événements avant que save() ne les libère.
     *
     * @return DomainEvent[]
     */
    public function peekEvents(): array
    {
        return $this->recordedEvents;
    }

    public function getVersion(): int
    {
        return $this->version;
    }
}
