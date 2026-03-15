<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Application\Projection\KycAuditTrailProjectorPort;
use App\Application\Projection\KycDecisionReportProjectorPort;
use App\Application\Projection\KycRequestStatusProjectorPort;
use App\Application\Projection\PendingManualReviewProjectorPort;
use App\Application\Projection\ProjectorPort;
use App\Domain\KycRequest\Port\DomainEventPublisherPort;

/**
 * Publisher qui propage chaque événement domaine à tous les projecteurs enregistrés.
 *
 * Utilisé dans l'environnement de test (env: test) afin que les projecteurs
 * in-memory soient automatiquement mis à jour lors de chaque commande HTTP.
 * Cela permet de tester les endpoints en lecture (GET /status, GET /audit…)
 * immédiatement après les commandes d'écriture sans rejeu manuel.
 */
final class ProjectionForwardingEventPublisher implements DomainEventPublisherPort
{
    /** @var ProjectorPort[] */
    private array $projectors;

    public function __construct(
        KycRequestStatusProjectorPort $statusProjector,
        KycAuditTrailProjectorPort $auditProjector,
        PendingManualReviewProjectorPort $pendingProjector,
        KycDecisionReportProjectorPort $reportProjector,
    ) {
        $this->projectors = [
            $statusProjector,
            $auditProjector,
            $pendingProjector,
            $reportProjector,
        ];
    }

    public function publishAll(array $events): void
    {
        foreach ($events as $event) {
            foreach ($this->projectors as $projector) {
                $projector->project($event);
            }
        }
    }
}
