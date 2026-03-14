<?php

declare(strict_types=1);

namespace App\Application\Port;

/**
 * Port secondaire : bus de commandes.
 *
 * Découple les émetteurs (contrôleurs, CLI) des handlers d'application.
 * Chaque commande est dispatchée à son handler unique.
 *
 * @throws \Throwable si le handler lève une exception
 */
interface CommandBusPort
{
    public function dispatch(object $command): void;
}
