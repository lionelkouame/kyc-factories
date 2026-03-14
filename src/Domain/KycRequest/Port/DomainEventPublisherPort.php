<?php

declare(strict_types=1);

namespace App\Domain\KycRequest\Port;

use App\Domain\KycRequest\Event\DomainEvent;

interface DomainEventPublisherPort
{
    /**
     * Publie une séquence d'événements de domaine vers le bus externe.
     *
     * @param DomainEvent[] $events
     */
    public function publishAll(array $events): void;
}
