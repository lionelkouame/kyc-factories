<?php

declare(strict_types=1);

namespace App\Domain\KycRequest\Event;

use Symfony\Component\Uid\Uuid;

/**
 * Classe de base de tous les événements de domaine.
 *
 * Chaque événement est immuable après construction.
 * Le champ `version` est assigné par AggregateRoot lors de l'enregistrement.
 */
abstract class DomainEvent
{
    public readonly string $eventId;
    public readonly \DateTimeImmutable $occurredAt;
    public int $version = 0;

    public function __construct()
    {
        $this->eventId = (string) Uuid::v7();
        $this->occurredAt = new \DateTimeImmutable();
    }

    abstract public function getAggregateId(): string;

    public function getAggregateType(): string
    {
        return 'KycRequest';
    }

    abstract public function getEventType(): string;

    /** @return array<string, mixed> */
    abstract public function getPayload(): array;

    /**
     * Sérialise l'événement pour persistance dans l'event store.
     *
     * @return array<string, mixed>
     */
    final public function toArray(): array
    {
        return [
            'eventId' => $this->eventId,
            'aggregateId' => $this->getAggregateId(),
            'aggregateType' => $this->getAggregateType(),
            'eventType' => $this->getEventType(),
            'payload' => $this->getPayload(),
            'occurredAt' => $this->occurredAt->format(\DateTimeInterface::ATOM),
            'version' => $this->version,
        ];
    }
}
