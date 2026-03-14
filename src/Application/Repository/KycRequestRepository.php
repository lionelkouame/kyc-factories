<?php

declare(strict_types=1);

namespace App\Application\Repository;

use App\Domain\KycRequest\Aggregate\KycRequest;
use App\Domain\KycRequest\Port\EventStorePort;
use App\Domain\KycRequest\ValueObject\KycRequestId;

final class KycRequestRepository
{
    public function __construct(
        private readonly EventStorePort $eventStore,
    ) {
    }

    public function get(KycRequestId $id): KycRequest
    {
        $events = $this->eventStore->load($id->toString());

        return KycRequest::reconstitute($events);
    }

    public function save(KycRequest $kycRequest): void
    {
        $events = $kycRequest->releaseEvents();

        if ($events === []) {
            return;
        }

        $expectedVersion = $kycRequest->getVersion() - \count($events);

        $this->eventStore->append(
            aggregateId: $kycRequest->getAggregateId(),
            events: $events,
            expectedVersion: $expectedVersion,
        );
    }
}
