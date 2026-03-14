<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Projection;

use App\Domain\KycRequest\Event\DocumentUploaded;
use App\Domain\KycRequest\Event\KycRequestSubmitted;
use App\Domain\KycRequest\Event\OcrExtractionSucceeded;
use App\Domain\KycRequest\ValueObject\ApplicantId;
use App\Domain\KycRequest\ValueObject\BlurVarianceScore;
use App\Domain\KycRequest\ValueObject\DocumentType;
use App\Domain\KycRequest\ValueObject\KycRequestId;
use App\Domain\KycRequest\ValueObject\OcrConfidenceScore;
use App\Infrastructure\Projection\InMemoryKycAuditTrailProjector;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires de InMemoryKycAuditTrailProjector.
 */
final class InMemoryKycAuditTrailProjectorTest extends TestCase
{
    private KycRequestId $id;
    private ApplicantId $applicantId;
    private InMemoryKycAuditTrailProjector $projector;

    protected function setUp(): void
    {
        $this->id = KycRequestId::generate();
        $this->applicantId = ApplicantId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $this->projector = new InMemoryKycAuditTrailProjector();
    }

    public function testProjectAddsEntryToTrail(): void
    {
        $this->projector->project(new KycRequestSubmitted($this->id, $this->applicantId, DocumentType::Cni));

        $trail = $this->projector->findByAggregateId($this->id->toString());
        self::assertNotNull($trail);
        self::assertCount(1, $trail->entries);
        self::assertSame('kyc_request.submitted', $trail->entries[0]->eventType);
    }

    public function testProjectAccumulatesAllEventsInOrder(): void
    {
        $this->projector->project(new KycRequestSubmitted($this->id, $this->applicantId, DocumentType::Cni));
        $this->projector->project(new DocumentUploaded($this->id, 'doc.jpg', 'image/jpeg', 1024, 300.0, BlurVarianceScore::fromFloat(120.0), 'sha256'));
        $this->projector->project(new OcrExtractionSucceeded($this->id, 'Doe', 'John', '1990-01-01', '2030-12-31', 'AB123', 'MRZ', OcrConfidenceScore::fromFloat(95.0)));

        $trail = $this->projector->findByAggregateId($this->id->toString());
        self::assertCount(3, $trail?->entries);
        self::assertSame('kyc_request.submitted', $trail->entries[0]->eventType);
        self::assertSame('kyc_request.document_uploaded', $trail->entries[1]->eventType);
        self::assertSame('kyc_request.ocr_extraction_succeeded', $trail->entries[2]->eventType);
    }

    public function testEachEntryContainsCorrectPayload(): void
    {
        $event = new KycRequestSubmitted($this->id, $this->applicantId, DocumentType::Cni);
        $this->projector->project($event);

        $entry = $this->projector->findByAggregateId($this->id->toString())?->entries[0];
        self::assertNotNull($entry);
        self::assertSame($this->id->toString(), $entry->payload['kycRequestId']);
    }

    public function testFindByAggregateIdReturnsNullForUnknownId(): void
    {
        self::assertNull($this->projector->findByAggregateId('unknown-id'));
    }

    public function testResetClearsAllTrails(): void
    {
        $this->projector->project(new KycRequestSubmitted($this->id, $this->applicantId, DocumentType::Cni));
        $this->projector->reset();

        self::assertNull($this->projector->findByAggregateId($this->id->toString()));
    }

    public function testMultipleAggregatesAreTrackedSeparately(): void
    {
        $id2 = KycRequestId::generate();

        $this->projector->project(new KycRequestSubmitted($this->id, $this->applicantId, DocumentType::Cni));
        $this->projector->project(new KycRequestSubmitted($id2, $this->applicantId, DocumentType::Passeport));

        self::assertCount(1, $this->projector->findByAggregateId($this->id->toString())?->entries);
        self::assertCount(1, $this->projector->findByAggregateId($id2->toString())?->entries);
    }
}
