<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Projection;

use App\Domain\KycRequest\Event\KycApproved;
use App\Domain\KycRequest\Event\KycRejected;
use App\Domain\KycRequest\Event\KycRequestSubmitted;
use App\Domain\KycRequest\Event\ManualReviewRequested;
use App\Domain\KycRequest\ValueObject\ApplicantId;
use App\Domain\KycRequest\ValueObject\DocumentType;
use App\Domain\KycRequest\ValueObject\FailureReason;
use App\Domain\KycRequest\ValueObject\KycRequestId;
use App\Infrastructure\Projection\InMemoryPendingManualReviewProjector;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires de InMemoryPendingManualReviewProjector.
 */
final class InMemoryPendingManualReviewProjectorTest extends TestCase
{
    private KycRequestId $id;
    private InMemoryPendingManualReviewProjector $projector;

    protected function setUp(): void
    {
        $this->id = KycRequestId::generate();
        $this->projector = new InMemoryPendingManualReviewProjector();
    }

    public function testManualReviewRequestedAddsItemToQueue(): void
    {
        $this->projector->project(new ManualReviewRequested($this->id, 'officer-1', 'Document contesté'));

        $items = $this->projector->findAll();
        self::assertCount(1, $items);
        self::assertSame($this->id->toString(), $items[0]->kycRequestId);
        self::assertSame('officer-1', $items[0]->requestedBy);
        self::assertSame('Document contesté', $items[0]->reason);
    }

    public function testKycApprovedRemovesItemFromQueue(): void
    {
        $this->projector->project(new ManualReviewRequested($this->id, 'officer-1', 'Motif'));
        $this->projector->project(new KycApproved($this->id));

        self::assertCount(0, $this->projector->findAll());
    }

    public function testKycRejectedRemovesItemFromQueue(): void
    {
        $this->projector->project(new ManualReviewRequested($this->id, 'officer-1', 'Motif'));
        $this->projector->project(new KycRejected($this->id, [new FailureReason('E_MAN', 'Rejet manuel')]));

        self::assertCount(0, $this->projector->findAll());
    }

    public function testIrrelevantEventIsIgnored(): void
    {
        $applicant = ApplicantId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $this->projector->project(new KycRequestSubmitted($this->id, $applicant, DocumentType::Cni));

        self::assertCount(0, $this->projector->findAll());
    }

    public function testFindAllReturnsMultiplePendingItems(): void
    {
        $id2 = KycRequestId::generate();

        $this->projector->project(new ManualReviewRequested($this->id, 'officer-1', 'Raison A'));
        $this->projector->project(new ManualReviewRequested($id2, 'officer-2', 'Raison B'));

        self::assertCount(2, $this->projector->findAll());
    }

    public function testResetClearsAllPendingItems(): void
    {
        $this->projector->project(new ManualReviewRequested($this->id, 'officer-1', 'Motif'));
        $this->projector->reset();

        self::assertCount(0, $this->projector->findAll());
    }
}
