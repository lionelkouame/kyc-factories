<?php

declare(strict_types=1);

namespace App\Domain\KycRequest\Event;

use App\Domain\KycRequest\ValueObject\KycRequestId;
use App\Domain\KycRequest\ValueObject\OcrConfidenceScore;

final class OcrExtractionSucceeded extends DomainEvent
{
    public function __construct(
        public readonly KycRequestId $kycRequestId,
        public readonly ?string $lastName,
        public readonly ?string $firstName,
        public readonly ?string $birthDate,
        public readonly ?string $expiryDate,
        public readonly ?string $documentId,
        public readonly ?string $mrz,
        public readonly OcrConfidenceScore $confidenceScore,
    ) {
        parent::__construct();
    }

    public function getAggregateId(): string
    {
        return $this->kycRequestId->toString();
    }

    public function getEventType(): string
    {
        return 'kyc_request.ocr_extraction_succeeded';
    }

    public function getPayload(): array
    {
        return [
            'kycRequestId' => $this->kycRequestId->toString(),
            'lastName' => $this->lastName,
            'firstName' => $this->firstName,
            'birthDate' => $this->birthDate,
            'expiryDate' => $this->expiryDate,
            'documentId' => $this->documentId,
            'mrz' => $this->mrz,
            'confidenceScore' => $this->confidenceScore->toFloat(),
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new static(
            kycRequestId: KycRequestId::fromString(self::str($payload, 'kycRequestId')),
            lastName: self::strOrNull($payload, 'lastName'),
            firstName: self::strOrNull($payload, 'firstName'),
            birthDate: self::strOrNull($payload, 'birthDate'),
            expiryDate: self::strOrNull($payload, 'expiryDate'),
            documentId: self::strOrNull($payload, 'documentId'),
            mrz: self::strOrNull($payload, 'mrz'),
            confidenceScore: OcrConfidenceScore::fromFloat(self::float($payload, 'confidenceScore')),
        );
    }
}
