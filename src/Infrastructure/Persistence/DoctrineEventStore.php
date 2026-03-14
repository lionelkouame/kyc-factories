<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\KycRequest\Event\DomainEvent;
use App\Domain\KycRequest\Exception\KycRequestNotFoundException;
use App\Domain\KycRequest\Exception\OptimisticConcurrencyException;
use App\Domain\KycRequest\Port\EventStorePort;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class DoctrineEventStore implements EventStorePort
{
    private const TABLE = 'kyc_events';

    public function __construct(
        private readonly Connection $connection,
        private readonly EventSerializer $serializer,
    ) {
    }

    /**
     * @param DomainEvent[] $events
     */
    public function append(string $aggregateId, array $events, int $expectedVersion): void
    {
        if ($events === []) {
            return;
        }

        try {
            $this->connection->transactional(function () use ($events, $expectedVersion): void {
                $version = $expectedVersion;

                foreach ($events as $event) {
                    ++$version;
                    $event->version = $version;

                    $row = $this->serializer->serialize($event);

                    $this->connection->insert(self::TABLE, $row);
                }
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new OptimisticConcurrencyException(
                sprintf('Concurrency conflict on aggregate "%s" at version %d.', $aggregateId, $expectedVersion),
                previous: $e,
            );
        }
    }

    /**
     * @return DomainEvent[]
     */
    public function load(string $aggregateId): array
    {
        return $this->loadFrom($aggregateId, 0);
    }

    /**
     * @return DomainEvent[]
     */
    public function loadFrom(string $aggregateId, int $fromVersion): array
    {
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT * FROM %s WHERE aggregate_id = :id AND version > :v ORDER BY version ASC',
                self::TABLE,
            ),
            ['id' => $aggregateId, 'v' => $fromVersion],
        );

        if ($rows === [] && $fromVersion === 0) {
            throw new KycRequestNotFoundException(
                sprintf('No events found for aggregate "%s".', $aggregateId),
            );
        }

        return array_map($this->serializer->deserialize(...), $rows);
    }
}
