<?php

declare(strict_types=1);

namespace App\Domain\KycRequest\Event;

use App\Domain\KycRequest\ValueObject\KycRequestId;

final class ManualReviewDecisionRecorded extends DomainEvent
{
    public function __construct(
        public readonly KycRequestId $kycRequestId,
        public readonly string $reviewerId,
        public readonly string $decision,
        public readonly string $justification,
    ) {
        parent::__construct();
    }

    public function getAggregateId(): string
    {
        return $this->kycRequestId->toString();
    }

    public function getEventType(): string
    {
        return 'kyc_request.manual_review_decision_recorded';
    }

    public function getPayload(): array
    {
        return [
            'kycRequestId' => $this->kycRequestId->toString(),
            'reviewerId' => $this->reviewerId,
            'decision' => $this->decision,
            'justification' => $this->justification,
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new static(
            kycRequestId: KycRequestId::fromString(self::str($payload, 'kycRequestId')),
            reviewerId: self::str($payload, 'reviewerId'),
            decision: self::str($payload, 'decision'),
            justification: self::str($payload, 'justification'),
        );
    }
}
