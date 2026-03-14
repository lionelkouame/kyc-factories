<?php

declare(strict_types=1);

namespace App\Domain\KycRequest\Event;

use App\Domain\KycRequest\ValueObject\FailureReason;
use App\Domain\KycRequest\ValueObject\KycRequestId;

final class DocumentRejectedOnUpload extends DomainEvent
{
    public function __construct(
        public readonly KycRequestId $kycRequestId,
        public readonly FailureReason $failureReason,
    ) {
        parent::__construct();
    }

    public function getAggregateId(): string
    {
        return $this->kycRequestId->toString();
    }

    public function getEventType(): string
    {
        return 'kyc_request.document_rejected_on_upload';
    }

    public function getPayload(): array
    {
        return [
            'kycRequestId' => $this->kycRequestId->toString(),
            'failureCode' => $this->failureReason->code,
            'failureMessage' => $this->failureReason->message,
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new static(
            kycRequestId: KycRequestId::fromString(self::str($payload, 'kycRequestId')),
            failureReason: new FailureReason(self::str($payload, 'failureCode'), self::str($payload, 'failureMessage')),
        );
    }
}
