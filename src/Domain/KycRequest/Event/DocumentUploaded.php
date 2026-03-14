<?php

declare(strict_types=1);

namespace App\Domain\KycRequest\Event;

use App\Domain\KycRequest\ValueObject\BlurVarianceScore;
use App\Domain\KycRequest\ValueObject\KycRequestId;

final class DocumentUploaded extends DomainEvent
{
    public function __construct(
        public readonly KycRequestId $kycRequestId,
        public readonly string $storagePath,
        public readonly string $mimeType,
        public readonly int $sizeBytes,
        public readonly float $dpi,
        public readonly BlurVarianceScore $blurVariance,
        public readonly string $sha256Hash,
    ) {
        parent::__construct();
    }

    public function getAggregateId(): string
    {
        return $this->kycRequestId->toString();
    }

    public function getEventType(): string
    {
        return 'kyc_request.document_uploaded';
    }

    public function getPayload(): array
    {
        return [
            'kycRequestId' => $this->kycRequestId->toString(),
            'storagePath' => $this->storagePath,
            'mimeType' => $this->mimeType,
            'sizeBytes' => $this->sizeBytes,
            'dpi' => $this->dpi,
            'blurVariance' => $this->blurVariance->toFloat(),
            'sha256Hash' => $this->sha256Hash,
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new static(
            kycRequestId: KycRequestId::fromString(self::str($payload, 'kycRequestId')),
            storagePath: self::str($payload, 'storagePath'),
            mimeType: self::str($payload, 'mimeType'),
            sizeBytes: self::int($payload, 'sizeBytes'),
            dpi: self::float($payload, 'dpi'),
            blurVariance: BlurVarianceScore::fromFloat(self::float($payload, 'blurVariance')),
            sha256Hash: self::str($payload, 'sha256Hash'),
        );
    }
}
