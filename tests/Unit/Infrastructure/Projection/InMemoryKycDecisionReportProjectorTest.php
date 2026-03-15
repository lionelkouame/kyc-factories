<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Projection;

use App\Domain\KycRequest\Event\KycApproved;
use App\Domain\KycRequest\Event\KycRejected;
use App\Domain\KycRequest\Event\KycRequestSubmitted;
use App\Domain\KycRequest\ValueObject\ApplicantId;
use App\Domain\KycRequest\Event\OcrExtractionSucceeded;
use App\Domain\KycRequest\ValueObject\OcrConfidenceScore;
use Symfony\Component\Uid\Uuid;
use App\Domain\KycRequest\ValueObject\DocumentType;
use App\Domain\KycRequest\ValueObject\FailureReason;
use App\Domain\KycRequest\ValueObject\KycRequestId;
use App\Infrastructure\Projection\InMemoryKycDecisionReportProjector;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires de InMemoryKycDecisionReportProjector.
 *
 * Critères d'acceptance US-12 :
 *  - Comptage approuvés/rejetés dans la période
 *  - En cours = soumis dans la période, pas encore décidés
 *  - Taux d'approbation = approuvés / (approuvés + rejetés), entre 0.0 et 1.0
 *  - 0.0 si aucune décision
 */
final class InMemoryKycDecisionReportProjectorTest extends TestCase
{
    private InMemoryKycDecisionReportProjector $projector;

    protected function setUp(): void
    {
        $this->projector = new InMemoryKycDecisionReportProjector();
    }

    // ——————————————————————————————————————————————————
    // Helpers
    // ——————————————————————————————————————————————————

    private function submitEvent(KycRequestId $id, string $dateTime): KycRequestSubmitted
    {
        $event = new KycRequestSubmitted(
            $id,
            ApplicantId::fromString(Uuid::v4()->toString()),
            DocumentType::Passeport,
        );
        $event->hydrateMetadata('evt-' . uniqid(), new \DateTimeImmutable($dateTime), 1);

        return $event;
    }

    private function approveEvent(KycRequestId $id, string $dateTime): KycApproved
    {
        $event = new KycApproved($id);
        $event->hydrateMetadata('evt-' . uniqid(), new \DateTimeImmutable($dateTime), 2);

        return $event;
    }

    private function rejectEvent(KycRequestId $id, string $dateTime): KycRejected
    {
        $event = new KycRejected($id, [new FailureReason('E_TEST', 'test')]);
        $event->hydrateMetadata('evt-' . uniqid(), new \DateTimeImmutable($dateTime), 2);

        return $event;
    }

    // ——————————————————————————————————————————————————
    // Tests
    // ——————————————————————————————————————————————————

    public function testEmptyPeriodReturnsZeros(): void
    {
        $report = $this->projector->getReport(
            new \DateTimeImmutable('2024-01-01'),
            new \DateTimeImmutable('2024-01-31'),
        );

        self::assertSame(0, $report->approvedCount);
        self::assertSame(0, $report->rejectedCount);
        self::assertSame(0, $report->inProgressCount);
        self::assertSame(0.0, $report->approvalRate);
        self::assertSame('2024-01-01', $report->dateFrom);
        self::assertSame('2024-01-31', $report->dateTo);
    }

    public function testCountsApprovedRequestsInPeriod(): void
    {
        $id1 = KycRequestId::generate();
        $id2 = KycRequestId::generate();

        $this->projector->project($this->approveEvent($id1, '2024-06-15 10:00:00'));
        $this->projector->project($this->approveEvent($id2, '2024-06-20 12:00:00'));

        $report = $this->projector->getReport(
            new \DateTimeImmutable('2024-06-01'),
            new \DateTimeImmutable('2024-06-30'),
        );

        self::assertSame(2, $report->approvedCount);
        self::assertSame(0, $report->rejectedCount);
        self::assertSame(1.0, $report->approvalRate);
    }

    public function testCountsRejectedRequestsInPeriod(): void
    {
        $id = KycRequestId::generate();
        $this->projector->project($this->rejectEvent($id, '2024-06-10 09:00:00'));

        $report = $this->projector->getReport(
            new \DateTimeImmutable('2024-06-01'),
            new \DateTimeImmutable('2024-06-30'),
        );

        self::assertSame(0, $report->approvedCount);
        self::assertSame(1, $report->rejectedCount);
        self::assertSame(0.0, $report->approvalRate);
    }

    public function testApprovalRateCalculation(): void
    {
        $id1 = KycRequestId::generate();
        $id2 = KycRequestId::generate();
        $id3 = KycRequestId::generate();

        $this->projector->project($this->approveEvent($id1, '2024-06-01 08:00:00'));
        $this->projector->project($this->approveEvent($id2, '2024-06-02 08:00:00'));
        $this->projector->project($this->rejectEvent($id3, '2024-06-03 08:00:00'));

        $report = $this->projector->getReport(
            new \DateTimeImmutable('2024-06-01'),
            new \DateTimeImmutable('2024-06-30'),
        );

        // 2 / 3 ≈ 0.6667 (ratio 0.0–1.0)
        self::assertSame(2, $report->approvedCount);
        self::assertSame(1, $report->rejectedCount);
        self::assertEqualsWithDelta(2 / 3, $report->approvalRate, 0.001);
    }

    public function testInProgressCountsSubmittedButNotDecided(): void
    {
        $id1 = KycRequestId::generate(); // approved
        $id2 = KycRequestId::generate(); // rejected
        $id3 = KycRequestId::generate(); // in progress
        $id4 = KycRequestId::generate(); // in progress

        $this->projector->project($this->submitEvent($id1, '2024-06-05'));
        $this->projector->project($this->submitEvent($id2, '2024-06-06'));
        $this->projector->project($this->submitEvent($id3, '2024-06-07'));
        $this->projector->project($this->submitEvent($id4, '2024-06-08'));

        $this->projector->project($this->approveEvent($id1, '2024-06-10'));
        $this->projector->project($this->rejectEvent($id2, '2024-06-11'));
        // id3 et id4 restent en cours

        $report = $this->projector->getReport(
            new \DateTimeImmutable('2024-06-01'),
            new \DateTimeImmutable('2024-06-30'),
        );

        self::assertSame(2, $report->inProgressCount);
    }

    public function testEventsOutsidePeriodAreExcluded(): void
    {
        $idBefore = KycRequestId::generate();
        $idAfter  = KycRequestId::generate();
        $idIn     = KycRequestId::generate();

        $this->projector->project($this->approveEvent($idBefore, '2024-05-31 23:59:59'));
        $this->projector->project($this->approveEvent($idAfter, '2024-07-01 00:00:00'));
        $this->projector->project($this->approveEvent($idIn, '2024-06-01 00:00:00'));

        $report = $this->projector->getReport(
            new \DateTimeImmutable('2024-06-01'),
            new \DateTimeImmutable('2024-06-30'),
        );

        self::assertSame(1, $report->approvedCount);
    }

    public function testBoundaryDatesAreInclusive(): void
    {
        $id1 = KycRequestId::generate();
        $id2 = KycRequestId::generate();

        // Approuvés exactement aux bornes (matin/soir)
        $this->projector->project($this->approveEvent($id1, '2024-06-01 00:00:00'));
        $this->projector->project($this->approveEvent($id2, '2024-06-30 23:59:59'));

        $report = $this->projector->getReport(
            new \DateTimeImmutable('2024-06-01'),
            new \DateTimeImmutable('2024-06-30'),
        );

        self::assertSame(2, $report->approvedCount);
    }

    public function testResetClearsAllData(): void
    {
        $id = KycRequestId::generate();
        $this->projector->project($this->approveEvent($id, '2024-06-15'));

        $this->projector->reset();

        $report = $this->projector->getReport(
            new \DateTimeImmutable('2024-06-01'),
            new \DateTimeImmutable('2024-06-30'),
        );

        self::assertSame(0, $report->approvedCount);
    }

    public function testIrrelevantEventIsIgnored(): void
    {
        $id = KycRequestId::generate();

        // OcrExtractionSucceeded est sans effet sur le rapport de décisions
        $otherEvent = new OcrExtractionSucceeded(
            $id,
            'John',
            'DOE',
            '1990-01-01',
            '2030-01-01',
            'PASSPORT-001',
            str_repeat('X', 44) . "\n" . str_repeat('X', 44),
            OcrConfidenceScore::fromFloat(98.5),
        );
        $otherEvent->hydrateMetadata('evt-x', new \DateTimeImmutable('2024-06-15'), 1);
        $this->projector->project($otherEvent);

        $report = $this->projector->getReport(
            new \DateTimeImmutable('2024-06-01'),
            new \DateTimeImmutable('2024-06-30'),
        );

        self::assertSame(0, $report->approvedCount);
        self::assertSame(0, $report->rejectedCount);
        self::assertSame(0, $report->inProgressCount);
    }
}
