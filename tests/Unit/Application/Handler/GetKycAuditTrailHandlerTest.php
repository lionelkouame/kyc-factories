<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Handler;

use App\Application\Handler\GetKycAuditTrailHandler;
use App\Application\Projection\KycAuditTrailProjectorPort;
use App\Application\Query\GetKycAuditTrail;
use App\Application\Query\ReadModel\KycAuditTrailView;
use App\Domain\KycRequest\Exception\KycRequestNotFoundException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class GetKycAuditTrailHandlerTest extends TestCase
{
    private KycAuditTrailProjectorPort&MockObject $projector;
    private GetKycAuditTrailHandler $handler;

    protected function setUp(): void
    {
        $this->projector = $this->createMock(KycAuditTrailProjectorPort::class);
        $this->handler = new GetKycAuditTrailHandler($this->projector);
    }

    public function testHandleReturnsTrailWhenFound(): void
    {
        $id = 'some-uuid';
        $trail = new KycAuditTrailView($id, []);

        $this->projector->method('findByAggregateId')->with($id)->willReturn($trail);

        $result = $this->handler->handle(new GetKycAuditTrail($id));

        self::assertSame($trail, $result);
    }

    public function testHandleThrowsWhenNotFound(): void
    {
        $this->projector->method('findByAggregateId')->willReturn(null);

        $this->expectException(KycRequestNotFoundException::class);

        $this->handler->handle(new GetKycAuditTrail('unknown-id'));
    }
}
