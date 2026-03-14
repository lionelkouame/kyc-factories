<?php

declare(strict_types=1);

namespace App\Application\Handler;

use App\Application\Command\ValidateKyc;
use App\Application\Repository\KycRequestRepositoryPort;
use App\Domain\KycRequest\Port\DomainEventPublisherPort;
use App\Domain\KycRequest\ValueObject\KycRequestId;

final class ValidateKycHandler
{
    public function __construct(
        private readonly KycRequestRepositoryPort $repository,
        private readonly DomainEventPublisherPort $publisher,
    ) {
    }

    public function handle(ValidateKyc $command): void
    {
        $id = KycRequestId::fromString($command->kycRequestId);
        $kycRequest = $this->repository->get($id);

        $kycRequest->validate(new \DateTimeImmutable('today'));

        $events = $kycRequest->peekEvents();
        $this->repository->save($kycRequest);
        $this->publisher->publishAll($events);
    }
}
