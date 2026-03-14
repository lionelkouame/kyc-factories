<?php

declare(strict_types=1);

namespace App\Infrastructure\Bus;

use App\Application\Port\CommandBusPort;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Adaptateur CommandBusPort utilisant Symfony Messenger.
 *
 * Les exceptions levées par les handlers sont encapsulées par Messenger
 * dans HandlerFailedException. Cet adaptateur les désenveloppe pour
 * que les appelants puissent catcher directement les exceptions métier.
 */
final class SymfonyMessengerCommandBus implements CommandBusPort
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    public function dispatch(object $command): void
    {
        try {
            $this->messageBus->dispatch($command);
        } catch (HandlerFailedException $e) {
            $cause = $e->getPrevious();
            if ($cause instanceof \Throwable) {
                throw $cause;
            }

            throw $e;
        }
    }
}
