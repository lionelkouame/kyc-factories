<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Handler;

use App\Application\Command\RecordManualReviewDecision;
use App\Application\Handler\RecordManualReviewDecisionHandler;
use App\Application\Repository\KycRequestRepositoryPort;
use App\Domain\KycRequest\Aggregate\KycRequest;
use App\Domain\KycRequest\Aggregate\KycStatus;
use App\Domain\KycRequest\Event\KycApproved;
use App\Domain\KycRequest\Event\KycRejected;
use App\Domain\KycRequest\Event\ManualReviewDecisionRecorded;
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

final class RecordManualReviewDecisionHandlerTest extends TestCase
{
    private KycRequestRepositoryPort&MockObject $repository;
    private DomainEventPublisherPort&MockObject $publisher;
    private RecordManualReviewDecisionHandler $handler;

    private KycRequestId $id;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(KycRequestRepositoryPort::class);
        $this->publisher = $this->createMock(DomainEventPublisherPort::class);
        $this->handler = new RecordManualReviewDecisionHandler($this->repository, $this->publisher);

        $this->id = KycRequestId::generate();
    }

    private function buildUnderManualReviewRequest(): KycRequest
    {
        $expiryDate = (new \DateTimeImmutable('+5 years'))->format('Y-m-d');

        $e1 = new \App\Domain\KycRequest\Event\KycRequestSubmitted($this->id, ApplicantId::fromString('550e8400-e29b-41d4-a716-446655440000'), DocumentType::Cni);
        $e1->version = 1;
        $e2 = new \App\Domain\KycRequest\Event\DocumentUploaded($this->id, 'docs/t.jpg', 'image/jpeg', 1_000_000, 300.0, BlurVarianceScore::fromFloat(120.0), 'sha256');
        $e2->version = 2;
        $e3 = new \App\Domain\KycRequest\Event\OcrExtractionSucceeded($this->id, 'DUPONT', 'Jean', '1990-01-01', $expiryDate, 'FR123456789', str_pad('IDFRA', 30, '<')."\n".str_pad('FR123', 30, '<'), OcrConfidenceScore::fromFloat(85.0));
        $e3->version = 3;
        $e4 = new \App\Domain\KycRequest\Event\KycRejected($this->id, [new FailureReason('E_VAL_NAME', 'Nom invalide.')]);
        $e4->version = 4;
        $e5 = new \App\Domain\KycRequest\Event\ManualReviewRequested($this->id, 'agent@kyc.fr', 'Vérification.');
        $e5->version = 5;

        return KycRequest::reconstitute([$e1, $e2, $e3, $e4, $e5]);
    }

    // ── Décision : approved ──────────────────────────────────────────────────

    public function testApprovedDecisionProducesManualReviewDecisionRecordedAndKycApproved(): void
    {
        $this->repository->method('get')->willReturn($this->buildUnderManualReviewRequest());

        $publishedEvents = null;
        $this->publisher->method('publishAll')
            ->willReturnCallback(function (array $events) use (&$publishedEvents): void {
                $publishedEvents = $events;
            });

        $this->handler->handle(new RecordManualReviewDecision(
            kycRequestId: $this->id->toString(),
            reviewerId: 'reviewer-001',
            decision: 'approved',
            justification: 'Document validé après vérification manuelle.',
        ));

        self::assertCount(2, $publishedEvents ?? []);
        self::assertInstanceOf(ManualReviewDecisionRecorded::class, ($publishedEvents ?? [])[0]);
        self::assertInstanceOf(KycApproved::class, ($publishedEvents ?? [])[1]);
    }

    public function testApprovedDecisionSetsStatusToApproved(): void
    {
        $this->repository->method('get')->willReturn($this->buildUnderManualReviewRequest());

        $capturedAggregate = null;
        $this->repository->method('save')
            ->willReturnCallback(function (KycRequest $agg) use (&$capturedAggregate): void {
                $capturedAggregate = $agg;
            });

        $this->handler->handle(new RecordManualReviewDecision(
            kycRequestId: $this->id->toString(),
            reviewerId: 'reviewer-001',
            decision: 'approved',
            justification: 'OK.',
        ));

        self::assertSame(KycStatus::Approved, $capturedAggregate?->getStatus());
    }

    // ── Décision : rejected ──────────────────────────────────────────────────

    public function testRejectedDecisionProducesManualReviewDecisionRecordedAndKycRejected(): void
    {
        $this->repository->method('get')->willReturn($this->buildUnderManualReviewRequest());

        $publishedEvents = null;
        $this->publisher->method('publishAll')
            ->willReturnCallback(function (array $events) use (&$publishedEvents): void {
                $publishedEvents = $events;
            });

        $this->handler->handle(new RecordManualReviewDecision(
            kycRequestId: $this->id->toString(),
            reviewerId: 'reviewer-001',
            decision: 'rejected',
            justification: 'Rejet confirmé après vérification.',
        ));

        self::assertCount(2, $publishedEvents ?? []);
        self::assertInstanceOf(ManualReviewDecisionRecorded::class, ($publishedEvents ?? [])[0]);
        self::assertInstanceOf(KycRejected::class, ($publishedEvents ?? [])[1]);
    }

    public function testRejectedDecisionSetsStatusToRejected(): void
    {
        $this->repository->method('get')->willReturn($this->buildUnderManualReviewRequest());

        $capturedAggregate = null;
        $this->repository->method('save')
            ->willReturnCallback(function (KycRequest $agg) use (&$capturedAggregate): void {
                $capturedAggregate = $agg;
            });

        $this->handler->handle(new RecordManualReviewDecision(
            kycRequestId: $this->id->toString(),
            reviewerId: 'reviewer-001',
            decision: 'rejected',
            justification: 'Rejeté.',
        ));

        self::assertSame(KycStatus::Rejected, $capturedAggregate?->getStatus());
    }

    public function testRejectedDecisionKycRejectedCarriesManualReviewCode(): void
    {
        $this->repository->method('get')->willReturn($this->buildUnderManualReviewRequest());

        $publishedEvents = null;
        $this->publisher->method('publishAll')
            ->willReturnCallback(function (array $events) use (&$publishedEvents): void {
                $publishedEvents = $events;
            });

        $this->handler->handle(new RecordManualReviewDecision(
            kycRequestId: $this->id->toString(),
            reviewerId: 'reviewer-002',
            decision: 'rejected',
            justification: 'Document non conforme.',
        ));

        /** @var KycRejected $rejectedEvent */
        $rejectedEvent = ($publishedEvents ?? [])[1];
        self::assertSame('MANUAL_REVIEW_REJECTED', $rejectedEvent->failureReasons[0]->code);
        self::assertSame('Document non conforme.', $rejectedEvent->failureReasons[0]->message);
    }

    // ── Invariant : état invalide ────────────────────────────────────────────

    public function testDecisionFromWrongStateThrowsInvalidTransition(): void
    {
        $this->expectException(InvalidTransitionException::class);

        // Agrégat en état Submitted — ne peut pas enregistrer une décision
        $submittedRequest = KycRequest::submit($this->id, ApplicantId::fromString('550e8400-e29b-41d4-a716-446655440000'), DocumentType::Cni);
        $submittedRequest->releaseEvents();

        $this->repository->method('get')->willReturn($submittedRequest);

        $this->handler->handle(new RecordManualReviewDecision(
            kycRequestId: $this->id->toString(),
            reviewerId: 'reviewer-001',
            decision: 'approved',
            justification: 'Test.',
        ));
    }

    // ── Données de l'événement ────────────────────────────────────────────────

    public function testDecisionEventCarriesReviewerIdAndJustification(): void
    {
        $this->repository->method('get')->willReturn($this->buildUnderManualReviewRequest());

        $publishedEvents = null;
        $this->publisher->method('publishAll')
            ->willReturnCallback(function (array $events) use (&$publishedEvents): void {
                $publishedEvents = $events;
            });

        $this->handler->handle(new RecordManualReviewDecision(
            kycRequestId: $this->id->toString(),
            reviewerId: 'reviewer-XYZ',
            decision: 'approved',
            justification: 'Tout est conforme.',
        ));

        /** @var ManualReviewDecisionRecorded $decisionEvent */
        $decisionEvent = ($publishedEvents ?? [])[0];
        self::assertSame('reviewer-XYZ', $decisionEvent->reviewerId);
        self::assertSame('approved', $decisionEvent->decision);
        self::assertSame('Tout est conforme.', $decisionEvent->justification);
    }
}
