<?php

declare(strict_types=1);

namespace App\Application\Command;

/**
 * Commande de reconstruction d'une projection (UC-04).
 *
 * Déclenche le rejeu de tous les événements depuis l'event store
 * à travers le projecteur désigné.
 */
final readonly class RebuildProjection
{
    public function __construct(
        /**
         * Nom du projecteur à reconstruire.
         * Doit correspondre à un ProjectorPort enregistré dans le conteneur.
         */
        public string $projectorName,
    ) {
    }
}
