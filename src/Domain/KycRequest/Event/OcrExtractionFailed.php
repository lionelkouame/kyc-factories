<?php

declare(strict_types=1);

namespace App\Domain\KycRequest\Event;

use App\Domain\KycRequest\ValueObject\FailureReason;
use App\Domain\KycRequest\ValueObject\KycRequestId;

final class OcrExtractionFailed extends DomainEvent
{
    public function __construct(
        public readonly KycRequestId $kycRequestId,
        public readonly FailureReason $failureReason,
        public readonly ?float $confidenceScore = null,
    ) {
        parent::__construct();
    }

    public function getAggregateId(): string
    {
        return $this->kycRequestId->toString();
    }

    public function getEventType(): string
    {
        return 'kyc_request.ocr_extraction_failed';
    }

    public function getPayload(): array
    {
        return [
            'kycRequestId' => $this->kycRequestId->toString(),
            'failureCode' => $this->failureReason->code,
            'failureMessage' => $this->failureReason->message,
            'confidenceScore' => $this->confidenceScore,
        ];
    }
}
