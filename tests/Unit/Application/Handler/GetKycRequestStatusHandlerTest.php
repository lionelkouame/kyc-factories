<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Handler;

use App\Application\Handler\GetKycRequestStatusHandler;
use App\Application\Projection\KycRequestStatusProjectorPort;
use App\Application\Query\GetKycRequestStatus;
use App\Application\Query\ReadModel\KycRequestStatusView;
use App\Domain\KycRequest\Exception\KycRequestNotFoundException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class GetKycRequestStatusHandlerTest extends TestCase
{
    private KycRequestStatusProjectorPort&MockObject $projector;
    private GetKycRequestStatusHandler $handler;

    protected function setUp(): void
    {
        $this->projector = $this->createMock(KycRequestStatusProjectorPort::class);
        $this->handler = new GetKycRequestStatusHandler($this->projector);
    }

    public function testHandleReturnsViewWhenFound(): void
    {
        $id = 'some-uuid';
        $view = new KycRequestStatusView($id, 'applicant-uuid', 'cni', 'approved', new \DateTimeImmutable());

        $this->projector->method('findById')->with($id)->willReturn($view);

        $result = $this->handler->handle(new GetKycRequestStatus($id));

        self::assertSame($view, $result);
    }

    public function testHandleThrowsWhenNotFound(): void
    {
        $this->projector->method('findById')->willReturn(null);

        $this->expectException(KycRequestNotFoundException::class);

        $this->handler->handle(new GetKycRequestStatus('unknown-id'));
    }
}
