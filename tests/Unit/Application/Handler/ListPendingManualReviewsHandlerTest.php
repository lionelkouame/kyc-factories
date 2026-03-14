<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Handler;

use App\Application\Handler\ListPendingManualReviewsHandler;
use App\Application\Projection\PendingManualReviewProjectorPort;
use App\Application\Query\ListPendingManualReviews;
use App\Application\Query\ReadModel\PendingManualReviewItem;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ListPendingManualReviewsHandlerTest extends TestCase
{
    private PendingManualReviewProjectorPort&MockObject $projector;
    private ListPendingManualReviewsHandler $handler;

    protected function setUp(): void
    {
        $this->projector = $this->createMock(PendingManualReviewProjectorPort::class);
        $this->handler = new ListPendingManualReviewsHandler($this->projector);
    }

    public function testHandleReturnsEmptyArrayWhenNoPendingReviews(): void
    {
        $this->projector->method('findAll')->willReturn([]);

        $result = $this->handler->handle(new ListPendingManualReviews());

        self::assertSame([], $result);
    }

    public function testHandleReturnsAllPendingItems(): void
    {
        $items = [
            new PendingManualReviewItem('id-1', 'applicant-1', 'officer-1', 'Raison A', new \DateTimeImmutable()),
            new PendingManualReviewItem('id-2', 'applicant-2', 'officer-2', 'Raison B', new \DateTimeImmutable()),
        ];

        $this->projector->method('findAll')->willReturn($items);

        $result = $this->handler->handle(new ListPendingManualReviews());

        self::assertCount(2, $result);
        self::assertSame($items[0], $result[0]);
        self::assertSame($items[1], $result[1]);
    }
}
