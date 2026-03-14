<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Domain\KycRequest\Port\EventStorePort;
use App\Infrastructure\Persistence\DoctrineEventStore;
use App\Infrastructure\Persistence\EventSerializer;
use App\Tests\Contract\EventStore\EventStoreContractTest;
use Doctrine\DBAL\DriverManager;

/**
 * Integration test — runs the full EventStore contract against a real SQLite database.
 */
final class DoctrineEventStoreTest extends EventStoreContractTest
{
    protected function createEventStore(): EventStorePort
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $connection->executeStatement(<<<'SQL'
            CREATE TABLE kyc_events (
                event_id       VARCHAR(36)  NOT NULL,
                aggregate_id   VARCHAR(36)  NOT NULL,
                aggregate_type VARCHAR(100) NOT NULL,
                event_type     VARCHAR(100) NOT NULL,
                payload        TEXT         NOT NULL,
                occurred_at    VARCHAR(32)  NOT NULL,
                version        INTEGER      NOT NULL,
                PRIMARY KEY (event_id),
                UNIQUE (aggregate_id, version)
            )
        SQL);

        return new DoctrineEventStore($connection, new EventSerializer());
    }
}
