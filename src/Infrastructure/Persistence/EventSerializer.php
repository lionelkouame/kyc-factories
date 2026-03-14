<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\KycRequest\Event\DocumentPurged;
use App\Domain\KycRequest\Event\DocumentRejectedOnUpload;
use App\Domain\KycRequest\Event\DocumentUploaded;
use App\Domain\KycRequest\Event\DomainEvent;
use App\Domain\KycRequest\Event\KycApproved;
use App\Domain\KycRequest\Event\KycRejected;
use App\Domain\KycRequest\Event\KycRequestSubmitted;
use App\Domain\KycRequest\Event\ManualReviewDecisionRecorded;
use App\Domain\KycRequest\Event\ManualReviewRequested;
use App\Domain\KycRequest\Event\OcrExtractionFailed;
use App\Domain\KycRequest\Event\OcrExtractionSucceeded;

/**
 * Maps event type strings to concrete DomainEvent classes and handles (de)serialization.
 *
 * @internal Infrastructure layer only — do not use outside DoctrineEventStore.
 */
final class EventSerializer
{
    /** @var array<string, class-string<DomainEvent>> */
    private const EVENT_TYPE_MAP = [
        'kyc_request.submitted'                        => KycRequestSubmitted::class,
        'kyc_request.document_uploaded'                => DocumentUploaded::class,
        'kyc_request.document_rejected_on_upload'      => DocumentRejectedOnUpload::class,
        'kyc_request.ocr_extraction_succeeded'         => OcrExtractionSucceeded::class,
        'kyc_request.ocr_extraction_failed'            => OcrExtractionFailed::class,
        'kyc_request.approved'                         => KycApproved::class,
        'kyc_request.rejected'                         => KycRejected::class,
        'kyc_request.manual_review_requested'          => ManualReviewRequested::class,
        'kyc_request.manual_review_decision_recorded'  => ManualReviewDecisionRecorded::class,
        'kyc_request.document_purged'                  => DocumentPurged::class,
    ];

    /**
     * Deserializes a raw event store row back to a DomainEvent.
     *
     * @param array<string, mixed> $row
     */
    public function deserialize(array $row): DomainEvent
    {
        $eventType = $this->requireString($row, 'event_type');

        if (!isset(self::EVENT_TYPE_MAP[$eventType])) {
            throw new \UnexpectedValueException(sprintf('Unknown event type "%s".', $eventType));
        }

        $class = self::EVENT_TYPE_MAP[$eventType];

        $payloadJson = $this->requireString($row, 'payload');
        /** @var array<string, mixed> $payload */
        $payload = json_decode($payloadJson, true, 512, \JSON_THROW_ON_ERROR);

        $event = $class::fromPayload($payload);

        $event->hydrateMetadata(
            eventId: $this->requireString($row, 'event_id'),
            occurredAt: new \DateTimeImmutable($this->requireString($row, 'occurred_at')),
            version: $this->requireInt($row, 'version'),
        );

        return $event;
    }

    /** @param array<string, mixed> $row */
    private function requireString(array $row, string $key): string
    {
        $v = $row[$key] ?? null;
        if (!\is_string($v)) {
            throw new \UnexpectedValueException(sprintf('Expected string at column "%s" in event store row.', $key));
        }

        return $v;
    }

    /** @param array<string, mixed> $row */
    private function requireInt(array $row, string $key): int
    {
        $v = $row[$key] ?? null;
        if (\is_int($v)) {
            return $v;
        }
        if (\is_string($v) || \is_float($v)) {
            return (int) $v;
        }
        throw new \UnexpectedValueException(sprintf('Expected int at column "%s" in event store row.', $key));
    }

    /**
     * Serializes a DomainEvent to a storable row (without aggregate_id / version, handled by caller).
     *
     * @return array<string, mixed>
     */
    public function serialize(DomainEvent $event): array
    {
        return [
            'event_id'       => $event->eventId,
            'aggregate_id'   => $event->getAggregateId(),
            'aggregate_type' => $event->getAggregateType(),
            'event_type'     => $event->getEventType(),
            'payload'        => json_encode($event->getPayload(), \JSON_THROW_ON_ERROR),
            'occurred_at'    => $event->occurredAt->format(\DateTimeInterface::ATOM),
            'version'        => $event->version,
        ];
    }
}
