<?php

declare(strict_types=1);

namespace App\Application\Handler;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use App\Application\Command\ExtractOcr;
use App\Application\Repository\KycRequestRepositoryPort;
use App\Domain\KycRequest\Port\DocumentStoragePort;
use App\Domain\KycRequest\Port\DomainEventPublisherPort;
use App\Domain\KycRequest\Port\OcrExtractionException;
use App\Domain\KycRequest\Port\OcrPort;
use App\Domain\KycRequest\ValueObject\FailureReason;
use App\Domain\KycRequest\ValueObject\KycRequestId;
use App\Domain\KycRequest\ValueObject\OcrConfidenceScore;

final class ExtractOcrHandler
{
    private const MIN_CONFIDENCE = 60.0;

    public function __construct(
        private readonly KycRequestRepositoryPort $repository,
        private readonly DocumentStoragePort $storage,
        private readonly OcrPort $ocr,
        private readonly DomainEventPublisherPort $publisher,
    ) {
    }

    #[AsMessageHandler]
    public function handle(ExtractOcr $command): void
    {
        $id = KycRequestId::fromString($command->kycRequestId);
        $kycRequest = $this->repository->get($id);

        $kycRequest->assertCanRunOcr();

        $fileContent = $this->storage->retrieve($kycRequest->getStoragePath() ?? '');

        try {
            $result = $this->ocr->extract($fileContent);
        } catch (OcrExtractionException $e) {
            $kycRequest->recordOcrFailure(
                new FailureReason($e->getFailureCode(), $e->getMessage()),
            );
            $events = $kycRequest->peekEvents();
            $this->repository->save($kycRequest);
            $this->publisher->publishAll($events);

            return;
        }

        if ($result->confidenceScore < self::MIN_CONFIDENCE) {
            $kycRequest->recordOcrFailure(
                new FailureReason('E_OCR_CONFIDENCE', \sprintf('Nous n\'avons pas pu lire votre document. Score de confiance : %.1f%% (minimum requis : %.0f%%).', $result->confidenceScore, self::MIN_CONFIDENCE)),
                $result->confidenceScore,
            );
            $events = $kycRequest->peekEvents();
            $this->repository->save($kycRequest);
            $this->publisher->publishAll($events);

            return;
        }

        $kycRequest->recordOcrSuccess(
            lastName: $result->lastName,
            firstName: $result->firstName,
            birthDate: $result->birthDate,
            expiryDate: $result->expiryDate,
            documentId: $result->documentId,
            mrz: $result->mrz,
            confidenceScore: OcrConfidenceScore::fromFloat($result->confidenceScore),
        );

        $events = $kycRequest->peekEvents();
        $this->repository->save($kycRequest);
        $this->publisher->publishAll($events);
    }
}
