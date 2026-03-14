<?php

declare(strict_types=1);

namespace App\Domain\KycRequest\Event;

use App\Domain\KycRequest\ValueObject\ApplicantId;
use App\Domain\KycRequest\ValueObject\DocumentType;
use App\Domain\KycRequest\ValueObject\KycRequestId;

final class KycRequestSubmitted extends DomainEvent
{
    public function __construct(
        public readonly KycRequestId $kycRequestId,
        public readonly ApplicantId $applicantId,
        public readonly DocumentType $documentType,
    ) {
        parent::__construct();
    }

    public function getAggregateId(): string
    {
        return $this->kycRequestId->toString();
    }

    public function getEventType(): string
    {
        return 'kyc_request.submitted';
    }

    public function getPayload(): array
    {
        return [
            'kycRequestId' => $this->kycRequestId->toString(),
            'applicantId' => $this->applicantId->toString(),
            'documentType' => $this->documentType->value,
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new static(
            KycRequestId::fromString(self::str($payload, 'kycRequestId')),
            ApplicantId::fromString(self::str($payload, 'applicantId')),
            DocumentType::from(self::str($payload, 'documentType')),
        );
    }
}
