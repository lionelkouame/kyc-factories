<?php

declare(strict_types=1);

namespace App\Domain\KycRequest\Event;

use App\Domain\KycRequest\ValueObject\FailureReason;
use App\Domain\KycRequest\ValueObject\KycRequestId;

final class KycRejected extends DomainEvent
{
    /**
     * @param FailureReason[] $failureReasons
     */
    public function __construct(
        public readonly KycRequestId $kycRequestId,
        public readonly array $failureReasons,
    ) {
        parent::__construct();
    }

    public function getAggregateId(): string
    {
        return $this->kycRequestId->toString();
    }

    public function getEventType(): string
    {
        return 'kyc_request.rejected';
    }

    public function getPayload(): array
    {
        return [
            'kycRequestId' => $this->kycRequestId->toString(),
            'failureReasons' => array_map(
                static fn (FailureReason $r) => ['code' => $r->code, 'message' => $r->message],
                $this->failureReasons,
            ),
        ];
    }

    public static function fromPayload(array $payload): static
    {
        /** @var array<array{code: string, message: string}> $reasons */
        $reasons = self::arr($payload, 'failureReasons');

        return new static(
            kycRequestId: KycRequestId::fromString(self::str($payload, 'kycRequestId')),
            failureReasons: array_map(
                static fn (array $r) => new FailureReason((string) $r['code'], (string) $r['message']),
                $reasons,
            ),
        );
    }
}
