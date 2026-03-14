<?php

declare(strict_types=1);

namespace App\Domain\KycRequest\Event;

use Symfony\Component\Uid\Uuid;

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

    abstract public function getEventType(): string;

    /** @return array<string, mixed> */
    abstract public function getPayload(): array;
}
