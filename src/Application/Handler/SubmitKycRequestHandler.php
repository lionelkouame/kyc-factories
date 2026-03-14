<?php

declare(strict_types=1);

namespace App\Application\Handler;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use App\Application\Command\SubmitKycRequest;
use App\Application\Repository\KycRequestRepositoryPort;
use App\Domain\KycRequest\Aggregate\KycRequest;
use App\Domain\KycRequest\Port\DomainEventPublisherPort;
use App\Domain\KycRequest\ValueObject\ApplicantId;
use App\Domain\KycRequest\ValueObject\DocumentType;
use App\Domain\KycRequest\ValueObject\KycRequestId;

final class SubmitKycRequestHandler
{
    public function __construct(
        private readonly KycRequestRepositoryPort $repository,
        private readonly DomainEventPublisherPort $publisher,
    ) {
    }

    #[AsMessageHandler]
    public function handle(SubmitKycRequest $command): void
    {
        $id = KycRequestId::fromString($command->kycRequestId);
        $applicantId = ApplicantId::fromString($command->applicantId);
        $documentType = DocumentType::from($command->documentType);

        $kycRequest = KycRequest::submit($id, $applicantId, $documentType);

        $events = $kycRequest->peekEvents();
        $this->repository->save($kycRequest);
        $this->publisher->publishAll($events);
    }
}
