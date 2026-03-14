<?php

declare(strict_types=1);

namespace App\Application\Handler;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use App\Application\Command\RecordManualReviewDecision;
use App\Application\Repository\KycRequestRepositoryPort;
use App\Domain\KycRequest\Port\DomainEventPublisherPort;
use App\Domain\KycRequest\ValueObject\KycRequestId;

final class RecordManualReviewDecisionHandler
{
    public function __construct(
        private readonly KycRequestRepositoryPort $repository,
        private readonly DomainEventPublisherPort $publisher,
    ) {
    }

    #[AsMessageHandler]
    public function handle(RecordManualReviewDecision $command): void
    {
        $id = KycRequestId::fromString($command->kycRequestId);
        $kycRequest = $this->repository->get($id);

        $kycRequest->recordManualReviewDecision(
            $command->reviewerId,
            $command->decision,
            $command->justification,
        );

        $events = $kycRequest->peekEvents();
        $this->repository->save($kycRequest);
        $this->publisher->publishAll($events);
    }
}
