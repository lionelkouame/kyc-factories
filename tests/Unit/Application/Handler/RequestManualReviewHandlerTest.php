<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Handler;

use App\Application\Command\RequestManualReview;
use App\Application\Handler\RequestManualReviewHandler;
use App\Application\Repository\KycRequestRepositoryPort;
use App\Domain\KycRequest\Aggregate\KycRequest;
use App\Domain\KycRequest\Aggregate\KycStatus;
use App\Domain\KycRequest\Event\ManualReviewRequested;
use App\Domain\KycRequest\Exception\InvalidTransitionException;
use App\Domain\KycRequest\Port\DomainEventPublisherPort;
use App\Domain\KycRequest\ValueObject\ApplicantId;
use App\Domain\KycRequest\ValueObject\BlurVarianceScore;
use App\Domain\KycRequest\ValueObject\DocumentType;
use App\Domain\KycRequest\ValueObject\FailureReason;
use App\Domain\KycRequest\ValueObject\KycRequestId;
use App\Domain\KycRequest\ValueObject\OcrConfidenceScore;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class RequestManualReviewHandlerTest extends TestCase
{
    private KycRequestRepositoryPort&MockObject $repository;
    private DomainEventPublisherPort&MockObject $publisher;
    private RequestManualReviewHandler $handler;

    private KycRequestId $id;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(KycRequestRepositoryPort::class);
        $this->publisher = $this->createMock(DomainEventPublisherPort::class);
        $this->handler = new RequestManualReviewHandler($this->repository, $this->publisher);

        $this->id = KycRequestId::generate();
    }

    private function buildRejectedRequest(): KycRequest
    {
        $expiryDate = (new \DateTimeImmutable('+5 years'))->format('Y-m-d');

        $e1 = new \App\Domain\KycRequest\Event\KycRequestSubmitted($this->id, ApplicantId::fromString('550e8400-e29b-41d4-a716-446655440000'), DocumentType::Cni);
        $e1->version = 1;
        $e2 = new \App\Domain\KycRequest\Event\DocumentUploaded($this->id, 'docs/test.jpg', 'image/jpeg', 1_000_000, 300.0, BlurVarianceScore::fromFloat(120.0), 'sha256');
        $e2->version = 2;
        $e3 = new \App\Domain\KycRequest\Event\OcrExtractionSucceeded($this->id, 'DUPONT', 'Jean', '1990-01-01', $expiryDate, 'FR123456789', str_pad('IDFRADU', 30, '<')."\n".str_pad('FR12345', 30, '<'), OcrConfidenceScore::fromFloat(85.0));
        $e3->version = 3;
        $e4 = new \App\Domain\KycRequest\Event\KycRejected($this->id, [new FailureReason('E_VAL_EXPIRED', 'Expiré.')]);
        $e4->version = 4;

        return KycRequest::reconstitute([$e1, $e2, $e3, $e4]);
    }

    public function testHandleProducesManualReviewRequestedEvent(): void
    {
        $this->repository->method('get')->willReturn($this->buildRejectedRequest());

        $publishedEvents = null;
        $this->publisher->method('publishAll')
            ->willReturnCallback(function (array $events) use (&$publishedEvents): void {
                $publishedEvents = $events;
            });

        $this->handler->handle(new RequestManualReview(
            kycRequestId: $this->id->toString(),
            requestedBy: 'agent@kyc.fr',
            reason: 'Vérification manuelle requise.',
        ));

        self::assertCount(1, $publishedEvents ?? []);
        self::assertInstanceOf(ManualReviewRequested::class, ($publishedEvents ?? [])[0]);
    }

    public function testHandleSetsStatusToUnderManualReview(): void
    {
        $this->repository->method('get')->willReturn($this->buildRejectedRequest());

        $capturedAggregate = null;
        $this->repository->method('save')
            ->willReturnCallback(function (KycRequest $agg) use (&$capturedAggregate): void {
                $capturedAggregate = $agg;
            });

        $this->handler->handle(new RequestManualReview(
            kycRequestId: $this->id->toString(),
            requestedBy: 'agent@kyc.fr',
            reason: 'Demande de révision.',
        ));

        self::assertSame(KycStatus::UnderManualReview, $capturedAggregate?->getStatus());
    }

    public function testHandleFromSubmittedStateThrowsInvalidTransition(): void
    {
        $this->expectException(InvalidTransitionException::class);

        // Agrégat en état Submitted — ne peut pas demander de révision
        $submittedRequest = KycRequest::submit($this->id, ApplicantId::fromString('550e8400-e29b-41d4-a716-446655440000'), DocumentType::Cni);
        $submittedRequest->releaseEvents();

        $this->repository->method('get')->willReturn($submittedRequest);

        $this->handler->handle(new RequestManualReview(
            kycRequestId: $this->id->toString(),
            requestedBy: 'agent@kyc.fr',
            reason: 'Test.',
        ));
    }

    public function testHandleEventCarriesRequestedByAndReason(): void
    {
        $this->repository->method('get')->willReturn($this->buildRejectedRequest());

        $publishedEvents = null;
        $this->publisher->method('publishAll')
            ->willReturnCallback(function (array $events) use (&$publishedEvents): void {
                $publishedEvents = $events;
            });

        $this->handler->handle(new RequestManualReview(
            kycRequestId: $this->id->toString(),
            requestedBy: 'compliance@fintech.fr',
            reason: 'Document potentiellement valide, demande re-vérification.',
        ));

        /** @var ManualReviewRequested $event */
        $event = ($publishedEvents ?? [])[0];
        self::assertSame('compliance@fintech.fr', $event->requestedBy);
        self::assertSame('Document potentiellement valide, demande re-vérification.', $event->reason);
    }
}
