<?php

declare(strict_types=1);

namespace App\Application\Handler;

use App\Application\Command\UploadDocument;
use App\Application\Repository\KycRequestRepositoryPort;
use App\Domain\KycRequest\Port\DocumentStoragePort;
use App\Domain\KycRequest\Port\DomainEventPublisherPort;
use App\Domain\KycRequest\ValueObject\BlurVarianceScore;
use App\Domain\KycRequest\ValueObject\FailureReason;
use App\Domain\KycRequest\ValueObject\KycRequestId;

final class UploadDocumentHandler
{
    private const MAX_SIZE_BYTES = 10 * 1024 * 1024; // 10 Mo
    private const MIN_DPI = 300.0;
    private const MIN_BLUR_VARIANCE = 100.0;
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'application/pdf'];

    public function __construct(
        private readonly KycRequestRepositoryPort $repository,
        private readonly DocumentStoragePort $storage,
        private readonly DomainEventPublisherPort $publisher,
    ) {
    }

    public function handle(UploadDocument $command): void
    {
        $id = KycRequestId::fromString($command->kycRequestId);
        $kycRequest = $this->repository->get($id);

        // Contrôles qualité — premier échec remonte immédiatement
        $rejectionReason = $this->checkQuality($command);

        if (null !== $rejectionReason) {
            $kycRequest->rejectDocumentOnUpload($rejectionReason);
            $events = $kycRequest->peekEvents();
            $this->repository->save($kycRequest);
            $this->publisher->publishAll($events);

            return;
        }

        // Qualité OK : stockage puis enregistrement
        $storagePath = $this->storage->store(
            $command->kycRequestId,
            $command->fileContent,
            $command->mimeType,
        );

        $kycRequest->uploadDocument(
            storagePath: $storagePath,
            mimeType: $command->mimeType,
            sizeBytes: $command->sizeBytes,
            dpi: $command->dpi,
            blurVariance: BlurVarianceScore::fromFloat($command->blurVariance),
            sha256Hash: $command->sha256Hash,
        );

        $events = $kycRequest->peekEvents();
        $this->repository->save($kycRequest);
        $this->publisher->publishAll($events);
    }

    private function checkQuality(UploadDocument $command): ?FailureReason
    {
        if ($command->sizeBytes > self::MAX_SIZE_BYTES) {
            return new FailureReason('E_UPLOAD_SIZE', \sprintf('Le fichier dépasse la taille maximale de %d Mo.', self::MAX_SIZE_BYTES / 1024 / 1024));
        }

        if (!\in_array($command->mimeType, self::ALLOWED_MIME_TYPES, true)) {
            return new FailureReason('E_UPLOAD_MIME', \sprintf('Le type MIME "%s" n\'est pas autorisé.', $command->mimeType));
        }

        if ($command->dpi < self::MIN_DPI) {
            return new FailureReason('E_UPLOAD_DPI', \sprintf('La résolution %.0f DPI est inférieure au minimum requis de %.0f DPI.', $command->dpi, self::MIN_DPI));
        }

        if ($command->blurVariance < self::MIN_BLUR_VARIANCE) {
            return new FailureReason('E_UPLOAD_BLUR', 'L\'image est trop floue. Prenez la photo dans un endroit bien éclairé en vous assurant que le texte est net.');
        }

        return null;
    }
}
