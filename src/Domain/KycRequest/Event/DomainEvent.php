<?php

declare(strict_types=1);

namespace App\Domain\KycRequest\Event;

use Symfony\Component\Uid\Uuid;

/**
 * Classe de base de tous les événements de domaine.
 *
 * Le champ `version` est assigné par AggregateRoot lors de l'enregistrement.
 * `eventId` et `occurredAt` sont mutables pour permettre la reconstitution
 * depuis l'event store via hydrateMetadata() — usage réservé à l'infrastructure.
 */
abstract class DomainEvent
{
    public string $eventId;
    public \DateTimeImmutable $occurredAt;
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
     * Reconstruit l'événement depuis son payload stocké.
     *
     * @param array<string, mixed> $payload
     */
    abstract public static function fromPayload(array $payload): static;

    // ── Helpers de désérialisation typés (usage réservé aux fromPayload()) ────

    /** @param array<string, mixed> $payload */
    protected static function str(array $payload, string $key): string
    {
        $v = $payload[$key] ?? null;
        if (!\is_string($v)) {
            throw new \UnexpectedValueException(sprintf('Expected string at key "%s".', $key));
        }

        return $v;
    }

    /** @param array<string, mixed> $payload */
    protected static function strOrNull(array $payload, string $key): ?string
    {
        $v = $payload[$key] ?? null;
        if ($v === null) {
            return null;
        }
        if (!\is_string($v)) {
            throw new \UnexpectedValueException(sprintf('Expected string or null at key "%s".', $key));
        }

        return $v;
    }

    /** @param array<string, mixed> $payload */
    protected static function int(array $payload, string $key): int
    {
        $v = $payload[$key] ?? null;
        if (!\is_int($v) && !\is_float($v) && !\is_string($v)) {
            throw new \UnexpectedValueException(sprintf('Expected int at key "%s".', $key));
        }

        return (int) $v;
    }

    /** @param array<string, mixed> $payload */
    protected static function float(array $payload, string $key): float
    {
        $v = $payload[$key] ?? null;
        if (!\is_float($v) && !\is_int($v) && !\is_string($v)) {
            throw new \UnexpectedValueException(sprintf('Expected float at key "%s".', $key));
        }

        return (float) $v;
    }

    /** @param array<string, mixed> $payload */
    protected static function floatOrNull(array $payload, string $key): ?float
    {
        $v = $payload[$key] ?? null;
        if ($v === null) {
            return null;
        }
        if (!\is_float($v) && !\is_int($v) && !\is_string($v)) {
            throw new \UnexpectedValueException(sprintf('Expected float or null at key "%s".', $key));
        }

        return (float) $v;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<mixed>
     */
    protected static function arr(array $payload, string $key): array
    {
        $v = $payload[$key] ?? null;
        if (!\is_array($v)) {
            throw new \UnexpectedValueException(sprintf('Expected array at key "%s".', $key));
        }

        return $v;
    }

    /**
     * Restaure les métadonnées depuis l'event store.
     *
     * @internal Réservé à EventSerializer (infrastructure de persistance).
     */
    final public function hydrateMetadata(string $eventId, \DateTimeImmutable $occurredAt, int $version): void
    {
        $this->eventId = $eventId;
        $this->occurredAt = $occurredAt;
        $this->version = $version;
    }

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
