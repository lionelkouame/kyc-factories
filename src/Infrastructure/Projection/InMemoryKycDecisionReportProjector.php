<?php

declare(strict_types=1);

namespace App\Infrastructure\Projection;

use App\Application\Projection\KycDecisionReportProjectorPort;
use App\Application\Query\ReadModel\KycDecisionReportView;
use App\Domain\KycRequest\Event\DomainEvent;
use App\Domain\KycRequest\Event\KycApproved;
use App\Domain\KycRequest\Event\KycRejected;
use App\Domain\KycRequest\Event\KycRequestSubmitted;

/**
 * Implémentation in-memory du rapport de décisions.
 *
 * Enregistre chaque soumission, approbation et rejet avec leur date.
 * getReport() filtre sur la période et agrège les compteurs.
 */
final class InMemoryKycDecisionReportProjector implements KycDecisionReportProjectorPort
{
    /** @var array<string, \DateTimeImmutable> kycRequestId → submittedAt */
    private array $submitted = [];

    /** @var array<string, \DateTimeImmutable> kycRequestId → approvedAt */
    private array $approved = [];

    /** @var array<string, \DateTimeImmutable> kycRequestId → rejectedAt */
    private array $rejected = [];

    public function project(DomainEvent $event): void
    {
        $id = $event->getAggregateId();

        match (true) {
            $event instanceof KycRequestSubmitted => $this->submitted[$id] = $event->occurredAt,
            $event instanceof KycApproved         => $this->approved[$id]  = $event->occurredAt,
            $event instanceof KycRejected         => $this->rejected[$id]  = $event->occurredAt,
            default                               => null,
        };
    }

    public function reset(): void
    {
        $this->submitted = [];
        $this->approved  = [];
        $this->rejected  = [];
    }

    public function getReport(\DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo): KycDecisionReportView
    {
        $from = $dateFrom->setTime(0, 0, 0);
        $to   = $dateTo->setTime(23, 59, 59);

        $approvedCount  = $this->countInPeriod($this->approved, $from, $to);
        $rejectedCount  = $this->countInPeriod($this->rejected, $from, $to);

        // En cours : soumises dans la période mais pas encore approuvées ni rejetées
        $inProgressCount = 0;
        foreach ($this->submitted as $id => $submittedAt) {
            if ($this->inPeriod($submittedAt, $from, $to)
                && !isset($this->approved[$id])
                && !isset($this->rejected[$id])
            ) {
                ++$inProgressCount;
            }
        }

        $decided     = $approvedCount + $rejectedCount;
        $approvalRate = $decided > 0 ? round($approvedCount / $decided * 100, 2) : 0.0;

        return new KycDecisionReportView(
            dateFrom:        $dateFrom->format('Y-m-d'),
            dateTo:          $dateTo->format('Y-m-d'),
            approvedCount:   $approvedCount,
            rejectedCount:   $rejectedCount,
            inProgressCount: $inProgressCount,
            approvalRate:    $approvalRate,
        );
    }

    /** @param array<string, \DateTimeImmutable> $map */
    private function countInPeriod(array $map, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $count = 0;
        foreach ($map as $date) {
            if ($this->inPeriod($date, $from, $to)) {
                ++$count;
            }
        }

        return $count;
    }

    private function inPeriod(\DateTimeImmutable $date, \DateTimeImmutable $from, \DateTimeImmutable $to): bool
    {
        return $date >= $from && $date <= $to;
    }
}
