<?php

declare(strict_types=1);

namespace App\Domain\KycRequest\Event;

use App\Domain\KycRequest\ValueObject\KycRequestId;

final class ManualReviewRequested extends DomainEvent
{
    public function __construct(
        public readonly KycRequestId $kycRequestId,
        public readonly string $requestedBy,
        public readonly string $reason,
    ) {
        parent::__construct();
    }

    public function getAggregateId(): string
    {
        return $this->kycRequestId->toString();
    }

    public function getEventType(): string
    {
        return 'kyc_request.manual_review_requested';
    }

    public function getPayload(): array
    {
        return [
            'kycRequestId' => $this->kycRequestId->toString(),
            'requestedBy' => $this->requestedBy,
            'reason' => $this->reason,
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new static(
            kycRequestId: KycRequestId::fromString(self::str($payload, 'kycRequestId')),
            requestedBy: self::str($payload, 'requestedBy'),
            reason: self::str($payload, 'reason'),
        );
    }
}
