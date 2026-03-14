<?php

declare(strict_types=1);

namespace App\Tests\Contract\EventStore;

use App\Domain\KycRequest\Aggregate\KycRequest;
use App\Domain\KycRequest\Event\DomainEvent;
use App\Domain\KycRequest\Exception\KycRequestNotFoundException;
use App\Domain\KycRequest\Exception\OptimisticConcurrencyException;
use App\Domain\KycRequest\Port\EventStorePort;
use App\Domain\KycRequest\ValueObject\ApplicantId;
use App\Domain\KycRequest\ValueObject\DocumentType;
use App\Domain\KycRequest\ValueObject\KycRequestId;
use PHPUnit\Framework\TestCase;

/**
 * Abstract contract test for EventStorePort.
 *
 * Any concrete adapter (Doctrine, in-memory, …) must pass this suite.
 * Extend this class and implement createEventStore().
 */
abstract class EventStoreContractTest extends TestCase
{
    abstract protected function createEventStore(): EventStorePort;

    private EventStorePort $store;

    protected function setUp(): void
    {
        $this->store = $this->createEventStore();
    }

    public function testAppendAndLoadReturnsEvents(): void
    {
        $id = KycRequestId::generate();
        $kycRequest = KycRequest::submit($id, ApplicantId::fromString((string) \Symfony\Component\Uid\Uuid::v4()), DocumentType::Cni);
        $events = $kycRequest->releaseEvents();

        $this->store->append($id->toString(), $events, 0);

        $loaded = $this->store->load($id->toString());

        self::assertCount(1, $loaded);
        self::assertSame('kyc_request.submitted', $loaded[0]->getEventType());
        self::assertSame($id->toString(), $loaded[0]->getAggregateId());
        self::assertSame(1, $loaded[0]->version);
    }

    public function testLoadFromReturnsOnlyEventsAfterVersion(): void
    {
        $id = KycRequestId::generate();
        $event1 = $this->makeSubmittedEvent($id);
        $event2 = $this->makeSubmittedEvent($id);

        $this->store->append($id->toString(), [$event1], 0);
        $this->store->append($id->toString(), [$event2], 1);

        $loaded = $this->store->loadFrom($id->toString(), 1);

        self::assertCount(1, $loaded);
        self::assertSame(2, $loaded[0]->version);
    }

    public function testLoadThrowsWhenAggregateNotFound(): void
    {
        $this->expectException(KycRequestNotFoundException::class);
        $this->store->load(KycRequestId::generate()->toString());
    }

    public function testAppendEmptyEventsDoesNothing(): void
    {
        $id = KycRequestId::generate()->toString();
        $this->store->append($id, [], 0);

        $this->expectException(KycRequestNotFoundException::class);
        $this->store->load($id);
    }

    public function testOptimisticConcurrencyDetected(): void
    {
        $id = KycRequestId::generate();
        $event = $this->makeSubmittedEvent($id);
        $event->version = 1;

        $this->store->append($id->toString(), [$event], 0);

        $conflictEvent = $this->makeSubmittedEvent($id);
        $conflictEvent->version = 1; // duplicate version → conflict

        $this->expectException(OptimisticConcurrencyException::class);
        $this->store->append($id->toString(), [$conflictEvent], 0);
    }

    private function makeSubmittedEvent(KycRequestId $id): DomainEvent
    {
        $kycRequest = KycRequest::submit($id, ApplicantId::fromString((string) \Symfony\Component\Uid\Uuid::v4()), DocumentType::Cni);
        $events = $kycRequest->releaseEvents();

        return $events[0];
    }
}
