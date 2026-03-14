<?php

declare(strict_types=1);

namespace App\Domain\KycRequest\Event;

use App\Domain\KycRequest\ValueObject\KycRequestId;

final class DocumentPurged extends DomainEvent
{
    public function __construct(
        public readonly KycRequestId $kycRequestId,
    ) {
        parent::__construct();
    }

    public function getAggregateId(): string
    {
        return $this->kycRequestId->toString();
    }

    public function getEventType(): string
    {
        return 'kyc_request.document_purged';
    }

    public function getPayload(): array
    {
        return [
            'kycRequestId' => $this->kycRequestId->toString(),
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new static(KycRequestId::fromString(self::str($payload, 'kycRequestId')));
    }
}
