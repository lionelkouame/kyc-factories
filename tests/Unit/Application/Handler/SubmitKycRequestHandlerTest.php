<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Handler;

use App\Application\Command\SubmitKycRequest;
use App\Application\Handler\SubmitKycRequestHandler;
use App\Application\Repository\KycRequestRepositoryPort;
use App\Domain\KycRequest\Aggregate\KycRequest;
use App\Domain\KycRequest\Aggregate\KycStatus;
use App\Domain\KycRequest\Event\KycRequestSubmitted;
use App\Domain\KycRequest\Port\DomainEventPublisherPort;
use App\Domain\KycRequest\ValueObject\DocumentType;
use App\Domain\KycRequest\ValueObject\KycRequestId;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SubmitKycRequestHandlerTest extends TestCase
{
    private KycRequestRepositoryPort&MockObject $repository;
    private DomainEventPublisherPort&MockObject $publisher;
    private SubmitKycRequestHandler $handler;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(KycRequestRepositoryPort::class);
        $this->publisher = $this->createMock(DomainEventPublisherPort::class);
        $this->handler = new SubmitKycRequestHandler($this->repository, $this->publisher);
    }

    public function testHandleCreatesAndSavesKycRequest(): void
    {
        $kycRequestId = KycRequestId::generate()->toString();

        $capturedAggregate = null;
        $this->repository->expects(self::once())
            ->method('save')
            ->willReturnCallback(function (KycRequest $agg) use (&$capturedAggregate): void {
                $capturedAggregate = $agg;
            });

        $this->handler->handle(new SubmitKycRequest(
            kycRequestId: $kycRequestId,
            applicantId: '550e8400-e29b-41d4-a716-446655440000',
            documentType: 'cni',
        ));

        self::assertNotNull($capturedAggregate);
        self::assertSame(KycStatus::Submitted, $capturedAggregate->getStatus());
        self::assertSame($kycRequestId, $capturedAggregate->getId()->toString());
        self::assertSame(DocumentType::Cni, $capturedAggregate->getDocumentType());
    }

    public function testHandlePublishesKycRequestSubmittedEvent(): void
    {

        $publishedEvents = null;
        $this->publisher->expects(self::once())
            ->method('publishAll')
            ->willReturnCallback(function (array $events) use (&$publishedEvents): void {
                $publishedEvents = $events;
            });

        $this->handler->handle(new SubmitKycRequest(
            kycRequestId: KycRequestId::generate()->toString(),
            applicantId: '550e8400-e29b-41d4-a716-446655440000',
            documentType: 'passeport',
        ));

        self::assertNotNull($publishedEvents);
        self::assertCount(1, $publishedEvents);
        self::assertInstanceOf(KycRequestSubmitted::class, $publishedEvents[0]);
    }

    public function testHandleWithAllDocumentTypes(): void
    {
        foreach (DocumentType::cases() as $type) {

            $capturedAggregate = null;
            $this->repository
                ->method('save')
                ->willReturnCallback(function (KycRequest $agg) use (&$capturedAggregate): void {
                    $capturedAggregate = $agg;
                });

            $this->handler->handle(new SubmitKycRequest(
                kycRequestId: KycRequestId::generate()->toString(),
                applicantId: '550e8400-e29b-41d4-a716-446655440000',
                documentType: $type->value,
            ));

            self::assertSame($type, $capturedAggregate?->getDocumentType());
        }
    }
}
