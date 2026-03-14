<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Handler;

use App\Application\Command\RebuildProjection;
use App\Application\Handler\RebuildProjectionHandler;
use App\Application\Projection\ProjectorPort;
use App\Domain\KycRequest\Event\KycApproved;
use App\Domain\KycRequest\Event\KycRequestSubmitted;
use App\Domain\KycRequest\Port\EventStorePort;
use App\Domain\KycRequest\ValueObject\ApplicantId;
use App\Domain\KycRequest\ValueObject\DocumentType;
use App\Domain\KycRequest\ValueObject\KycRequestId;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires de RebuildProjectionHandler (UC-04).
 */
final class RebuildProjectionHandlerTest extends TestCase
{
    private EventStorePort&MockObject $eventStore;
    private ProjectorPort&MockObject $projector;
    private RebuildProjectionHandler $handler;

    protected function setUp(): void
    {
        $this->eventStore = $this->createMock(EventStorePort::class);
        $this->projector = $this->createMock(ProjectorPort::class);
        $this->handler = new RebuildProjectionHandler($this->eventStore, $this->projector);
    }

    public function testHandleResetsProjectorBeforeReplaying(): void
    {
        $this->eventStore->method('loadAll')->willReturn([]);
        $this->projector->expects(self::once())->method('reset');

        $this->handler->handle(new RebuildProjection('kyc_request_status'));
    }

    public function testHandleProjectsEachEventFromStore(): void
    {
        $id = KycRequestId::generate();
        $applicantId = ApplicantId::fromString('550e8400-e29b-41d4-a716-446655440000');

        $events = [
            new KycRequestSubmitted($id, $applicantId, DocumentType::Cni),
            new KycApproved($id),
        ];

        $this->eventStore->method('loadAll')->willReturn($events);

        $this->projector->expects(self::exactly(2))
            ->method('project')
            ->willReturnCallback(static function ($event) use ($events): void {
                static $index = 0;
                self::assertSame($events[$index++], $event);
            });

        $this->handler->handle(new RebuildProjection('kyc_request_status'));
    }

    public function testHandleWithEmptyStoreOnlyCallsReset(): void
    {
        $this->eventStore->method('loadAll')->willReturn([]);
        $this->projector->expects(self::once())->method('reset');
        $this->projector->expects(self::never())->method('project');

        $this->handler->handle(new RebuildProjection('kyc_audit_trail'));
    }

    public function testResetIsCalledBeforeFirstProjection(): void
    {
        $id = KycRequestId::generate();
        $applicantId = ApplicantId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $event = new KycRequestSubmitted($id, $applicantId, DocumentType::Cni);

        $this->eventStore->method('loadAll')->willReturn([$event]);

        $callOrder = [];
        $this->projector->expects(self::once())
            ->method('reset')
            ->willReturnCallback(static function () use (&$callOrder): void {
                $callOrder[] = 'reset';
            });

        $this->projector->expects(self::once())
            ->method('project')
            ->willReturnCallback(static function () use (&$callOrder): void {
                $callOrder[] = 'project';
            });

        $this->handler->handle(new RebuildProjection('kyc_request_status'));

        self::assertSame(['reset', 'project'], $callOrder);
    }

    public function testHandleIsIdempotent(): void
    {
        $id = KycRequestId::generate();
        $applicantId = ApplicantId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $event = new KycRequestSubmitted($id, $applicantId, DocumentType::Cni);

        $this->eventStore->method('loadAll')->willReturn([$event]);

        $this->projector->expects(self::exactly(2))->method('reset');
        $this->projector->expects(self::exactly(2))->method('project');

        $command = new RebuildProjection('kyc_request_status');
        $this->handler->handle($command);
        $this->handler->handle($command);
    }
}
