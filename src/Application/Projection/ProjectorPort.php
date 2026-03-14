<?php

declare(strict_types=1);

namespace App\Application\Projection;

use App\Domain\KycRequest\Event\DomainEvent;

/**
 * Port secondaire — projection d'événements de domaine vers un read model.
 *
 * Chaque projection concrète doit :
 * - Appliquer l'événement via project() pour mettre à jour son état interne
 * - Pouvoir être réinitialisée via reset() avant une reconstruction complète
 */
interface ProjectorPort
{
    /**
     * Applique un événement de domaine pour mettre à jour la projection.
     * Les événements non pertinents pour cette projection sont silencieusement ignorés.
     */
    public function project(DomainEvent $event): void;

    /**
     * Réinitialise l'état complet de la projection.
     * Appelé avant de rejouer tous les événements depuis l'event store.
     */
    public function reset(): void;
}
