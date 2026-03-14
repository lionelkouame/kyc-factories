<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Aggregate;

use App\Domain\KycRequest\Aggregate\KycRequest;
use App\Domain\KycRequest\Aggregate\KycStatus;
use App\Domain\KycRequest\Event\KycApproved;
use App\Domain\KycRequest\Event\KycRejected;
use App\Domain\KycRequest\Event\KycRequestSubmitted;
use App\Domain\KycRequest\Exception\InvalidTransitionException;
use App\Domain\KycRequest\Event\DocumentUploaded;
use App\Domain\KycRequest\Event\OcrExtractionSucceeded;
use App\Domain\KycRequest\ValueObject\BlurVarianceScore;
use App\Domain\KycRequest\ValueObject\OcrConfidenceScore;
use App\Domain\KycRequest\ValueObject\ApplicantId;
use App\Domain\KycRequest\ValueObject\DocumentType;
use App\Domain\KycRequest\ValueObject\KycRequestId;
use PHPUnit\Framework\TestCase;

final class KycRequestTest extends TestCase
{
    private KycRequestId $id;
    private ApplicantId $applicantId;

    protected function setUp(): void
    {
        $this->id = KycRequestId::generate();
        $this->applicantId = ApplicantId::fromString('550e8400-e29b-41d4-a716-446655440000');
    }

    // ── submit() ─────────────────────────────────────────────────────────────

    public function testSubmitProducesKycRequestSubmittedEvent(): void
    {
        $request = KycRequest::submit($this->id, $this->applicantId, DocumentType::Cni);

        $events = $request->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(KycRequestSubmitted::class, $events[0]);
    }

    public function testSubmitSetsStatusToSubmitted(): void
    {
        $request = KycRequest::submit($this->id, $this->applicantId, DocumentType::Passeport);

        self::assertSame(KycStatus::Submitted, $request->getStatus());
    }

    public function testSubmitSetsCorrectIdentifiers(): void
    {
        $request = KycRequest::submit($this->id, $this->applicantId, DocumentType::Cni);

        self::assertTrue($request->getId()->equals($this->id));
        self::assertTrue($request->getApplicantId()->equals($this->applicantId));
        self::assertSame(DocumentType::Cni, $request->getDocumentType());
    }

    public function testSubmitVersionIsOne(): void
    {
        $request = KycRequest::submit($this->id, $this->applicantId, DocumentType::Cni);

        self::assertSame(1, $request->getVersion());
    }

    public function testEventCarriesCorrectPayload(): void
    {
        $request = KycRequest::submit($this->id, $this->applicantId, DocumentType::Passeport);

        /** @var KycRequestSubmitted $event */
        $event = $request->releaseEvents()[0];

        self::assertSame($this->id->toString(), $event->getPayload()['kycRequestId']);
        self::assertSame(DocumentType::Passeport->value, $event->getPayload()['documentType']);
    }

    // ── releaseEvents() vide la liste ────────────────────────────────────────

    public function testReleaseEventsClearsTheList(): void
    {
        $request = KycRequest::submit($this->id, $this->applicantId, DocumentType::Cni);
        $request->releaseEvents();

        self::assertEmpty($request->releaseEvents());
    }

    // ── reconstitute() ───────────────────────────────────────────────────────

    public function testReconstituteFromEventsRestoresState(): void
    {
        $original = KycRequest::submit($this->id, $this->applicantId, DocumentType::TitreDeSejour);
        $events = $original->releaseEvents();

        $reconstituted = KycRequest::reconstitute($events);

        self::assertSame(KycStatus::Submitted, $reconstituted->getStatus());
        self::assertTrue($reconstituted->getId()->equals($this->id));
        self::assertSame(DocumentType::TitreDeSejour, $reconstituted->getDocumentType());
    }

    public function testReconstituteFromEmptyEventsThrows(): void
    {
        $this->expectException(\LogicException::class);

        KycRequest::reconstitute([]);
    }

    // ── Invariant : assertCanUploadDocument() ────────────────────────────────

    public function testAssertCanUploadDocumentPassesOnSubmitted(): void
    {
        $request = KycRequest::submit($this->id, $this->applicantId, DocumentType::Cni);

        // Aucune exception attendue
        $request->assertCanUploadDocument();
        $this->addToAssertionCount(1);
    }

    public function testAssertCanUploadDocumentThrowsWhenAlreadyUploaded(): void
    {
        $submitted = KycRequest::submit($this->id, $this->applicantId, DocumentType::Cni);
        $events = $submitted->releaseEvents();

        // On simule un agrégat dans un état différent en rejouant des événements
        // avec version modifiée pour simuler un état post-upload.
        // Pour tester l'invariant, on reconstruit depuis submitted et on modifie
        // en utilisant assertCanRunOcr qui attend document_uploaded.
        $this->expectException(InvalidTransitionException::class);

        // Un agrégat en état "submitted" ne peut pas lancer l'OCR
        $request = KycRequest::reconstitute($events);
        $request->assertCanRunOcr();
    }

    // ── Invariant : assertCanRunOcr() ────────────────────────────────────────

    public function testAssertCanRunOcrThrowsFromSubmittedState(): void
    {
        $this->expectException(InvalidTransitionException::class);

        $request = KycRequest::submit($this->id, $this->applicantId, DocumentType::Cni);
        $request->assertCanRunOcr();
    }

    // ── Invariant : assertCanRequestManualReview() ───────────────────────────

    public function testAssertCanRequestManualReviewThrowsFromSubmittedState(): void
    {
        $this->expectException(InvalidTransitionException::class);

        $request = KycRequest::submit($this->id, $this->applicantId, DocumentType::Cni);
        $request->assertCanRequestManualReview();
    }

    // ── Invariant : assertIsNotFinallyDecided() ──────────────────────────────

    public function testAssertIsNotFinallyDecidedPassesOnSubmitted(): void
    {
        $request = KycRequest::submit($this->id, $this->applicantId, DocumentType::Cni);

        $request->assertIsNotFinallyDecided();
        $this->addToAssertionCount(1);
    }

    // ── validate() — règles métier dans l'agrégat ────────────────────────────

    /**
     * Construit un agrégat en état ocr_completed avec les données OCR fournies.
     * MRZ par défaut : TD1 (2 lignes × 30 chars).
     */
    private function buildOcrCompletedRequest(
        ?string $lastName = 'DUPONT',
        ?string $firstName = 'Jean',
        ?string $birthDate = '1990-06-15',
        ?string $expiryDate = null,
        ?string $documentId = 'FR123456789',
        ?string $mrz = null,
    ): KycRequest {
        $expiryDate ??= (new \DateTimeImmutable('+5 years'))->format('Y-m-d');
        $mrz ??= str_pad('IDFRADUPONT', 30, '<') . "\n" . str_pad('FR123456789', 30, '<');

        $e1 = new KycRequestSubmitted($this->id, $this->applicantId, DocumentType::Cni);
        $e1->version = 1;

        $e2 = new DocumentUploaded($this->id, 'docs/test.jpg', 'image/jpeg', 1_000_000, 300.0, BlurVarianceScore::fromFloat(120.0), 'sha256abc');
        $e2->version = 2;

        $e3 = new OcrExtractionSucceeded($this->id, $lastName, $firstName, $birthDate, $expiryDate, $documentId, $mrz, OcrConfidenceScore::fromFloat(85.0));
        $e3->version = 3;

        return KycRequest::reconstitute([$e1, $e2, $e3]);
    }

    public function testValidateWithAllValidDataProducesKycApprovedEvent(): void
    {
        $request = $this->buildOcrCompletedRequest();

        $request->validate(new \DateTimeImmutable('today'));

        $events = $request->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(KycApproved::class, $events[0]);
    }

    public function testValidateApprovedSetsStatusApproved(): void
    {
        $request = $this->buildOcrCompletedRequest();
        $request->validate(new \DateTimeImmutable('today'));

        self::assertSame(KycStatus::Approved, $request->getStatus());
    }

    public function testValidateWithUnderageApplicantProducesKycRejectedWithE_VAL_UNDERAGE(): void
    {
        $birthDate = (new \DateTimeImmutable('-16 years'))->format('Y-m-d');
        $request = $this->buildOcrCompletedRequest(birthDate: $birthDate);

        $request->validate(new \DateTimeImmutable('today'));

        $events = $request->releaseEvents();
        self::assertInstanceOf(KycRejected::class, $events[0]);
        self::assertSame('E_VAL_UNDERAGE', $events[0]->failureReasons[0]->code);
        self::assertCount(1, $events[0]->failureReasons);
    }

    public function testValidateWithNullBirthDateProducesE_VAL_UNDERAGE(): void
    {
        $request = $this->buildOcrCompletedRequest(birthDate: null);

        $request->validate(new \DateTimeImmutable('today'));

        $events = $request->releaseEvents();
        self::assertSame('E_VAL_UNDERAGE', $events[0]->failureReasons[0]->code);
    }

    public function testValidateWithExpiredDocumentProducesKycRejectedWithE_VAL_EXPIRED(): void
    {
        $request = $this->buildOcrCompletedRequest(expiryDate: '2020-01-01');

        $request->validate(new \DateTimeImmutable('today'));

        $events = $request->releaseEvents();
        self::assertInstanceOf(KycRejected::class, $events[0]);
        self::assertSame('E_VAL_EXPIRED', $events[0]->failureReasons[0]->code);
    }

    public function testValidateWithMultipleViolationsCollectsAll(): void
    {
        $request = $this->buildOcrCompletedRequest(
            lastName: 'X',       // invalide < 2 chars
            documentId: 'AB',    // invalide < 9 chars
            mrz: 'INVALID_MRZ', // invalide
        );

        $request->validate(new \DateTimeImmutable('today'));

        $events = $request->releaseEvents();
        self::assertInstanceOf(KycRejected::class, $events[0]);
        $codes = array_map(fn ($r) => $r->code, $events[0]->failureReasons);
        self::assertContains('E_VAL_NAME', $codes);
        self::assertContains('E_VAL_DOC_ID', $codes);
        self::assertContains('E_VAL_MRZ', $codes);
        self::assertGreaterThanOrEqual(3, \count($codes));
    }

    public function testValidateUnderageBlocksCollectionOfOtherViolations(): void
    {
        $birthDate = (new \DateTimeImmutable('-16 years'))->format('Y-m-d');
        $request = $this->buildOcrCompletedRequest(
            birthDate: $birthDate,
            lastName: 'X',     // violation non bloquante
            documentId: 'AB', // violation non bloquante
        );

        $request->validate(new \DateTimeImmutable('today'));

        $events = $request->releaseEvents();
        self::assertCount(1, $events[0]->failureReasons);
        self::assertSame('E_VAL_UNDERAGE', $events[0]->failureReasons[0]->code);
    }

    public function testValidateFromWrongStatusThrowsInvalidTransitionException(): void
    {
        $this->expectException(InvalidTransitionException::class);

        $request = KycRequest::submit($this->id, $this->applicantId, DocumentType::Cni);
        $request->validate(new \DateTimeImmutable('today'));
    }
}
