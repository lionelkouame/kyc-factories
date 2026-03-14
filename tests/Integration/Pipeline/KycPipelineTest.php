<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pipeline;

use App\Application\Command\ExtractOcr;
use App\Application\Command\RecordManualReviewDecision;
use App\Application\Command\RequestManualReview;
use App\Application\Command\SubmitKycRequest;
use App\Application\Command\UploadDocument;
use App\Application\Command\ValidateKyc;
use App\Application\Handler\ExtractOcrHandler;
use App\Application\Handler\RecordManualReviewDecisionHandler;
use App\Application\Handler\RequestManualReviewHandler;
use App\Application\Handler\SubmitKycRequestHandler;
use App\Application\Handler\UploadDocumentHandler;
use App\Application\Handler\ValidateKycHandler;
use App\Application\Repository\KycRequestRepository;
use App\Domain\KycRequest\Aggregate\KycStatus;
use App\Domain\KycRequest\Event\KycApproved;
use App\Domain\KycRequest\Event\KycRejected;
use App\Domain\KycRequest\Event\KycRequestSubmitted;
use App\Domain\KycRequest\Event\ManualReviewDecisionRecorded;
use App\Domain\KycRequest\Exception\OptimisticConcurrencyException;
use App\Domain\KycRequest\Port\EventStorePort;
use App\Domain\KycRequest\Port\OcrExtractionException;
use App\Domain\KycRequest\Port\OcrExtractionResult;
use App\Domain\KycRequest\ValueObject\KycRequestId;
use App\Infrastructure\Messaging\InMemoryDomainEventPublisher;
use App\Infrastructure\Ocr\StubOcrAdapter;
use App\Infrastructure\Persistence\DoctrineEventStore;
use App\Infrastructure\Persistence\EventSerializer;
use App\Infrastructure\Storage\InMemoryDocumentStorage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests d'intégration — pipeline KYC complet (spec §11.2).
 *
 * Utilise DoctrineEventStore sur SQLite in-memory pour reproduire
 * le comportement de production sans dépendances externes.
 */
final class KycPipelineTest extends TestCase
{
    private Connection $connection;
    private EventStorePort $eventStore;
    private KycRequestRepository $repository;
    private InMemoryDocumentStorage $storage;
    private StubOcrAdapter $ocr;
    private InMemoryDomainEventPublisher $publisher;

    // Handlers
    private SubmitKycRequestHandler $submitHandler;
    private UploadDocumentHandler $uploadHandler;
    private ExtractOcrHandler $ocrHandler;
    private ValidateKycHandler $validateHandler;
    private RequestManualReviewHandler $manualReviewHandler;
    private RecordManualReviewDecisionHandler $decisionHandler;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE kyc_events (
                event_id       VARCHAR(36)  NOT NULL,
                aggregate_id   VARCHAR(36)  NOT NULL,
                aggregate_type VARCHAR(100) NOT NULL,
                event_type     VARCHAR(100) NOT NULL,
                payload        TEXT         NOT NULL,
                occurred_at    VARCHAR(32)  NOT NULL,
                version        INTEGER      NOT NULL,
                PRIMARY KEY (event_id),
                UNIQUE (aggregate_id, version)
            )
        SQL);

        $this->eventStore = new DoctrineEventStore($this->connection, new EventSerializer());
        $this->repository = new KycRequestRepository($this->eventStore);
        $this->storage    = new InMemoryDocumentStorage();
        $this->ocr        = new StubOcrAdapter();
        $this->publisher  = new InMemoryDomainEventPublisher();

        $this->submitHandler       = new SubmitKycRequestHandler($this->repository, $this->publisher);
        $this->uploadHandler       = new UploadDocumentHandler($this->repository, $this->storage, $this->publisher);
        $this->ocrHandler          = new ExtractOcrHandler($this->repository, $this->storage, $this->ocr, $this->publisher);
        $this->validateHandler     = new ValidateKycHandler($this->repository, $this->publisher);
        $this->manualReviewHandler = new RequestManualReviewHandler($this->repository, $this->publisher);
        $this->decisionHandler     = new RecordManualReviewDecisionHandler($this->repository, $this->publisher);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function submit(string $id, string $applicantId = '550e8400-e29b-41d4-a716-446655440000'): void
    {
        $this->submitHandler->handle(new SubmitKycRequest($id, $applicantId, 'cni'));
    }

    private function uploadValid(string $id): void
    {
        $this->uploadHandler->handle(new UploadDocument(
            kycRequestId: $id,
            fileContent: 'fake-jpeg-content',
            mimeType: 'image/jpeg',
            sizeBytes: 500_000,
            dpi: 300.0,
            blurVariance: 150.0,
            sha256Hash: hash('sha256', 'fake-jpeg-content'),
        ));
    }

    private function runOcr(string $id): void
    {
        $this->ocrHandler->handle(new ExtractOcr($id));
    }

    private function validate(string $id): void
    {
        $this->validateHandler->handle(new ValidateKyc($id));
    }

    private function getStatus(string $id): KycStatus
    {
        return $this->repository->get(KycRequestId::fromString($id))->getStatus();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Tests
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Spec §11.2 — Pipeline complet — données valides → approved, 4 events.
     */
    public function testFullHappyPathLeadsToApproved(): void
    {
        $id = KycRequestId::generate()->toString();

        $this->submit($id);
        $this->uploadValid($id);
        $this->runOcr($id);
        $this->validate($id);

        self::assertSame(KycStatus::Approved, $this->getStatus($id));

        $events = $this->eventStore->load($id);
        self::assertCount(4, $events);
        self::assertInstanceOf(KycApproved::class, end($events));
    }

    /**
     * Spec §11.2 — Pipeline complet — document expiré → rejected.
     */
    public function testExpiredDocumentLeadsToRejected(): void
    {
        $id = KycRequestId::generate()->toString();

        $this->ocr->willReturn(new OcrExtractionResult(
            lastName: 'DOE',
            firstName: 'John',
            birthDate: '1990-01-15',
            expiryDate: '2020-01-01', // expiré
            documentId: 'AB1234567',
            mrz: null,
            confidenceScore: 95.0,
        ));

        $this->submit($id);
        $this->uploadValid($id);
        $this->runOcr($id);
        $this->validate($id);

        self::assertSame(KycStatus::Rejected, $this->getStatus($id));

        $events = $this->eventStore->load($id);
        self::assertInstanceOf(KycRejected::class, end($events));

        $lastEvent = end($events);
        assert($lastEvent instanceof KycRejected);
        self::assertSame('E_VAL_EXPIRED', $lastEvent->failureReasons[0]->code);
    }

    /**
     * Spec §11.2 — Pipeline complet — demandeur mineur → rejected.
     */
    public function testUnderageApplicantLeadsToRejected(): void
    {
        $id = KycRequestId::generate()->toString();

        $this->ocr->willReturn(new OcrExtractionResult(
            lastName: 'DOE',
            firstName: 'John',
            birthDate: (new \DateTimeImmutable('-10 years'))->format('Y-m-d'), // mineur
            expiryDate: (new \DateTimeImmutable('+5 years'))->format('Y-m-d'),
            documentId: 'AB1234567',
            mrz: null,
            confidenceScore: 95.0,
        ));

        $this->submit($id);
        $this->uploadValid($id);
        $this->runOcr($id);
        $this->validate($id);

        self::assertSame(KycStatus::Rejected, $this->getStatus($id));

        $lastEvent = end($this->eventStore->load($id));
        assert($lastEvent instanceof KycRejected);
        self::assertSame('E_VAL_UNDERAGE', $lastEvent->failureReasons[0]->code);
    }

    /**
     * Spec §11.2 — OCR confiance faible → ocr_failed.
     */
    public function testLowOcrConfidenceLeadsToOcrFailed(): void
    {
        $id = KycRequestId::generate()->toString();

        $this->ocr->willReturn(new OcrExtractionResult(
            lastName: null,
            firstName: null,
            birthDate: null,
            expiryDate: null,
            documentId: null,
            mrz: null,
            confidenceScore: 30.0, // < 60 %
        ));

        $this->submit($id);
        $this->uploadValid($id);
        $this->runOcr($id);

        self::assertSame(KycStatus::OcrFailed, $this->getStatus($id));
    }

    /**
     * Spec §11.2 — Image floue → document_rejected.
     */
    public function testBlurryDocumentLeadsToDocumentRejected(): void
    {
        $id = KycRequestId::generate()->toString();

        $this->submit($id);
        $this->uploadHandler->handle(new UploadDocument(
            kycRequestId: $id,
            fileContent: 'blurry-content',
            mimeType: 'image/jpeg',
            sizeBytes: 500_000,
            dpi: 300.0,
            blurVariance: 50.0, // < 100 → trop floue
            sha256Hash: hash('sha256', 'blurry-content'),
        ));

        self::assertSame(KycStatus::DocumentRejected, $this->getStatus($id));
    }

    /**
     * Spec §11.2 — OCR timeout (exception levée par OcrPort) → ocr_failed.
     */
    public function testOcrTimeoutLeadsToOcrFailed(): void
    {
        $id = KycRequestId::generate()->toString();

        $this->ocr->willThrow(new OcrExtractionException('E_OCR_TIMEOUT', 'Timeout OCR'));

        $this->submit($id);
        $this->uploadValid($id);
        $this->runOcr($id);

        self::assertSame(KycStatus::OcrFailed, $this->getStatus($id));
    }

    /**
     * Spec §11.2 — Révision manuelle → approuvée.
     */
    public function testManualReviewLeadsToApproved(): void
    {
        $id = KycRequestId::generate()->toString();

        // Pipeline jusqu'au rejet
        $this->ocr->willReturn(new OcrExtractionResult(
            lastName: 'DOE',
            firstName: 'John',
            birthDate: '1990-01-15',
            expiryDate: '2020-01-01',
            documentId: 'AB1234567',
            mrz: null,
            confidenceScore: 95.0,
        ));

        $this->submit($id);
        $this->uploadValid($id);
        $this->runOcr($id);
        $this->validate($id);

        self::assertSame(KycStatus::Rejected, $this->getStatus($id));

        // Révision manuelle
        $this->manualReviewHandler->handle(new RequestManualReview($id, 'officer-42', 'Document contesté'));
        self::assertSame(KycStatus::UnderManualReview, $this->getStatus($id));

        $this->decisionHandler->handle(new RecordManualReviewDecision($id, 'officer-42', 'approved', 'Document valide après vérification'));
        self::assertSame(KycStatus::Approved, $this->getStatus($id));

        $events = $this->eventStore->load($id);
        $types = array_map(static fn ($e) => $e->getEventType(), $events);
        self::assertContains('kyc_request.manual_review_decision_recorded', $types);
        self::assertInstanceOf(ManualReviewDecisionRecorded::class, array_values(array_filter($events, static fn ($e) => $e instanceof ManualReviewDecisionRecorded))[0]);
    }

    /**
     * Spec §11.2 — Concurrence sur même agrégat → OptimisticConcurrencyException.
     */
    public function testOptimisticConcurrencyOnSameAggregate(): void
    {
        $id = KycRequestId::generate()->toString();
        $this->submit($id);

        // Charger deux instances indépendantes du même agrégat
        $kycId = KycRequestId::fromString($id);
        $instance1 = $this->repository->get($kycId);
        $instance2 = $this->repository->get($kycId);

        // Sauvegarder la première instance
        $instance1->uploadDocument('path1.jpg', 'image/jpeg', 500_000, 300.0, \App\Domain\KycRequest\ValueObject\BlurVarianceScore::fromFloat(150.0), 'sha256a');
        $this->repository->save($instance1);

        // Tenter de sauvegarder la deuxième instance (conflit de version)
        $instance2->uploadDocument('path2.jpg', 'image/jpeg', 500_000, 300.0, \App\Domain\KycRequest\ValueObject\BlurVarianceScore::fromFloat(150.0), 'sha256b');

        $this->expectException(OptimisticConcurrencyException::class);
        $this->repository->save($instance2);
    }

    /**
     * Spec §11.2 — Reconstruction de projection → état identique.
     */
    public function testProjectionRebuildsToIdenticalState(): void
    {
        $id = KycRequestId::generate()->toString();

        $this->submit($id);
        $this->uploadValid($id);
        $this->runOcr($id);
        $this->validate($id);

        // Reconstruction
        $projector = new \App\Infrastructure\Projection\InMemoryKycRequestStatusProjector();
        foreach ($this->eventStore->loadAll() as $event) {
            $projector->project($event);
        }

        $view = $projector->findById($id);
        self::assertNotNull($view);
        self::assertSame('approved', $view->status);
    }

    /**
     * KycRequestSubmitted est bien le premier événement publié.
     */
    public function testSubmitPublishesKycRequestSubmittedEvent(): void
    {
        $id = KycRequestId::generate()->toString();
        $this->submit($id);

        $published = $this->publisher->getPublishedEvents();
        self::assertCount(1, $published);
        self::assertInstanceOf(KycRequestSubmitted::class, $published[0]);
    }
}
