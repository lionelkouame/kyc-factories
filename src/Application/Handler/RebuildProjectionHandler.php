<?php

declare(strict_types=1);

namespace App\Application\Handler;

use App\Application\Command\RebuildProjection;
use App\Application\Projection\ProjectorPort;
use App\Domain\KycRequest\Port\EventStorePort;

/**
 * Handler UC-04 — Reconstruction d'une projection par rejeu de l'event store.
 *
 * Scénario :
 * 1. Réinitialise le projecteur (reset).
 * 2. Charge tous les événements depuis l'event store dans l'ordre chronologique.
 * 3. Projette chaque événement sur le projecteur.
 *
 * La reconstruction est idempotente : exécuter ce handler plusieurs fois
 * produit toujours le même état final.
 */
final class RebuildProjectionHandler
{
    public function __construct(
        private readonly EventStorePort $eventStore,
        private readonly ProjectorPort $projector,
    ) {
    }

    public function handle(RebuildProjection $command): void
    {
        $this->projector->reset();

        foreach ($this->eventStore->loadAll() as $event) {
            $this->projector->project($event);
        }
    }
}
