<?php

declare(strict_types=1);

namespace App\Domain\KycRequest\Port;

use App\Domain\KycRequest\Event\DomainEvent;
use App\Domain\KycRequest\Exception\OptimisticConcurrencyException;

/**
 * Port sortant — persistance et chargement des événements de domaine.
 *
 * Toute implémentation doit garantir :
 * - L'ordre des événements (version ASC)
 * - L'unicité de la paire (aggregateId, version)
 * - Le rejet par OptimisticConcurrencyException si la version attendue ne correspond pas
 */
interface EventStorePort
{
    /**
     * Persiste une séquence d'événements pour un agrégat.
     *
     * @param DomainEvent[] $events
     *
     * @throws OptimisticConcurrencyException si la version courante ≠ expectedVersion
     */
    public function append(string $aggregateId, array $events, int $expectedVersion): void;

    /**
     * Charge tous les événements d'un agrégat, dans l'ordre (version ASC).
     *
     * @return DomainEvent[]
     */
    public function load(string $aggregateId): array;

    /**
     * Charge les événements d'un agrégat à partir d'une version donnée (pour les snapshots).
     *
     * @return DomainEvent[]
     */
    public function loadFrom(string $aggregateId, int $fromVersion): array;

    /**
     * Retourne tous les événements de tous les agrégats, dans l'ordre chronologique.
     * Utilisé exclusivement pour la reconstruction de projections (UC-04).
     *
     * @return iterable<DomainEvent>
     */
    public function loadAll(): iterable;
}
