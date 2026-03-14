<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Handler;

use App\Application\Handler\GetKycDecisionReportHandler;
use App\Application\Projection\KycDecisionReportProjectorPort;
use App\Application\Query\GetKycDecisionReport;
use App\Application\Query\ReadModel\KycDecisionReportView;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class GetKycDecisionReportHandlerTest extends TestCase
{
    private KycDecisionReportProjectorPort&MockObject $projector;
    private GetKycDecisionReportHandler $handler;

    protected function setUp(): void
    {
        $this->projector = $this->createMock(KycDecisionReportProjectorPort::class);
        $this->handler   = new GetKycDecisionReportHandler($this->projector);
    }

    public function testHandleDelegatesToProjectorWithParsedDates(): void
    {
        $view = new KycDecisionReportView('2024-06-01', '2024-06-30', 5, 2, 3, 71.43);

        $this->projector
            ->expects(self::once())
            ->method('getReport')
            ->with(
                self::callback(fn (\DateTimeImmutable $d) => $d->format('Y-m-d') === '2024-06-01'),
                self::callback(fn (\DateTimeImmutable $d) => $d->format('Y-m-d') === '2024-06-30'),
            )
            ->willReturn($view);

        $result = $this->handler->handle(new GetKycDecisionReport('2024-06-01', '2024-06-30'));

        self::assertSame($view, $result);
    }

    public function testHandlePassesDateRangeCorrectly(): void
    {
        $view = new KycDecisionReportView('2024-01-01', '2024-12-31', 0, 0, 0, 0.0);

        $this->projector
            ->method('getReport')
            ->willReturn($view);

        $result = $this->handler->handle(new GetKycDecisionReport('2024-01-01', '2024-12-31'));

        self::assertSame('2024-01-01', $result->dateFrom);
        self::assertSame('2024-12-31', $result->dateTo);
    }
}
