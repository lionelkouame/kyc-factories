<?php

declare(strict_types=1);

namespace App\Tests\Functional\Http;

use App\Domain\KycRequest\Port\OcrExtractionException;
use App\Domain\KycRequest\Port\OcrExtractionResult;
use App\Domain\KycRequest\Port\OcrPort;
use App\Infrastructure\Ocr\StubOcrAdapter;

/**
 * Tests d'intégration HTTP — pipeline KYC complet.
 *
 * Ces tests exercent le pipeline de bout en bout via l'API HTTP :
 * - Soumission, upload, OCR, validation, révision manuelle.
 * - Endpoints de lecture (statut, audit trail, revues en attente, rapport).
 * - Cas d'erreur : mauvais état, données invalides, ressource introuvable.
 *
 * Infrastructure :
 * - SQLite file-based (var/app_test.db) via DoctrineEventStore.
 * - Tous les projecteurs sont in-memory et remis à zéro entre chaque test.
 * - Le publisher de test (ProjectionForwardingEventPublisher) garantit que
 *   les projecteurs sont alimentés automatiquement après chaque commande.
 * - L'adaptateur OCR est le StubOcrAdapter configurable.
 */
final class KycPipelineApiTest extends AbstractKycHttpTestCase
{
    // ──────────────────────────────────────────────────────────────────────────
    // Données de test
    // ──────────────────────────────────────────────────────────────────────────

    private const APPLICANT_VALID = 'a1b2c3d4-e5f6-4789-8012-3456789abcde';
    private const APPLICANT_ALT   = 'b2c3d4e5-f6a7-4891-9023-456789abcdef';
    private const APPLICANT_THIRD = 'c3d4e5f6-a7b8-4902-a034-56789abcdef0';

    // ──────────────────────────────────────────────────────────────────────────
    // Soumission d'une demande KYC (POST /api/kyc)
    // ──────────────────────────────────────────────────────────────────────────

    public function testSubmitKycRequestReturns201WithUuidKycRequestId(): void
    {
        $body = $this->jsonPost('/api/kyc', [
            'applicantId'  => self::APPLICANT_VALID,
            'documentType' => 'passeport',
        ]);

        self::assertSame(201, $this->statusCode());
        self::assertArrayHasKey('kycRequestId', $body);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            (string) $body['kycRequestId'],
            'kycRequestId doit être un UUID v4 valide',
        );    }

    public function testSubmitWithEachDocumentType(): void
    {
        foreach (['cni', 'passeport', 'titre_de_sejour'] as $type) {
            $body = $this->jsonPost('/api/kyc', [
                'applicantId'  => self::APPLICANT_VALID,
                'documentType' => $type,
            ]);
            self::assertSame(201, $this->statusCode(), "documentType={$type} devrait être accepté");
            self::assertArrayHasKey('kycRequestId', $body);
        }
    }

    public function testSubmitWithInvalidDocumentTypeReturns422(): void
    {
        $this->jsonPost('/api/kyc', [
            'applicantId'  => self::APPLICANT_VALID,
            'documentType' => 'INVALID_TYPE',
        ]);

        self::assertSame(422, $this->statusCode());
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Upload de document (POST /api/kyc/{id}/document)
    // ──────────────────────────────────────────────────────────────────────────

    public function testUploadValidDocumentReturns204(): void
    {
        $id = $this->submitKyc();
        $this->uploadValidDocument($id);

        self::assertSame(204, $this->statusCode());
    }

    public function testUploadBlurryDocumentReturns204AndSetsDocumentRejectedStatus(): void
    {
        $id = $this->submitKyc();

        // blurVariance < 100 → document trop flou
        $this->uploadValidDocument($id, blurVariance: 50.0);

        $body = $this->jsonGet("/api/kyc/{$id}/status");
        self::assertSame(200, $this->statusCode());
        self::assertSame('document_rejected', $body['status']);
    }

    public function testUploadOnNonExistentRequestReturns422(): void
    {
        $this->jsonPost('/api/kyc/00000000-0000-0000-0000-000000000000/document', [
            'fileContent'  => base64_encode('content'),
            'mimeType'     => 'image/jpeg',
            'sizeBytes'    => 500_000,
            'dpi'          => 300.0,
            'blurVariance' => 150.0,
            'sha256Hash'   => hash('sha256', 'content'),
        ]);

        self::assertSame(422, $this->statusCode());
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Extraction OCR (POST /api/kyc/{id}/ocr)
    // ──────────────────────────────────────────────────────────────────────────

    public function testExtractOcrReturns204AfterUpload(): void
    {
        $id = $this->submitKyc();
        $this->uploadValidDocument($id);
        $this->runOcr($id);

        self::assertSame(204, $this->statusCode());
    }

    public function testOcrOnSubmittedStateReturns422(): void
    {
        // OCR avant upload → état invalide
        $id = $this->submitKyc();
        $this->jsonPost("/api/kyc/{$id}/ocr");

        self::assertSame(422, $this->statusCode());
    }

    public function testOcrTimeoutCausesOcrFailedStatus(): void
    {
        $this->ocrStub()->willThrow(new OcrExtractionException('E_OCR_TIMEOUT', 'Timeout OCR'));

        $id = $this->submitKyc();
        $this->uploadValidDocument($id);
        $this->runOcr($id);

        $body = $this->jsonGet("/api/kyc/{$id}/status");
        self::assertSame('ocr_failed', $body['status']);
    }

    public function testLowConfidenceOcrCausesOcrFailedStatus(): void
    {
        $this->ocrStub()->willReturn(new OcrExtractionResult(
            lastName: null,
            firstName: null,
            birthDate: null,
            expiryDate: null,
            documentId: null,
            mrz: null,
            confidenceScore: 30.0, // < 60 %
        ));

        $id = $this->submitKyc();
        $this->uploadValidDocument($id);
        $this->runOcr($id);

        $body = $this->jsonGet("/api/kyc/{$id}/status");
        self::assertSame('ocr_failed', $body['status']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Validation métier (POST /api/kyc/{id}/validate)
    // ──────────────────────────────────────────────────────────────────────────

    public function testValidateReturns204AfterOcr(): void
    {
        $id = $this->submitKyc();
        $this->uploadValidDocument($id);
        $this->runOcr($id);
        $this->validate($id);

        self::assertSame(204, $this->statusCode());
    }

    public function testExpiredDocumentCausesRejectedStatus(): void
    {
        $this->ocrStub()->willReturn(new OcrExtractionResult(
            lastName: 'DUPONT',
            firstName: 'Marie',
            birthDate: '1985-06-20',
            expiryDate: '2020-01-01', // expiré
            documentId: 'FR1234567',
            mrz: str_repeat('A', 44) . "\n" . str_repeat('A', 44),
            confidenceScore: 95.0,
        ));

        $id = $this->runFullPipeline();

        $body = $this->jsonGet("/api/kyc/{$id}/status");
        self::assertSame('rejected', $body['status']);
    }

    public function testUnderageApplicantCausesRejectedStatus(): void
    {
        $this->ocrStub()->willReturn(new OcrExtractionResult(
            lastName: 'MARTIN',
            firstName: 'Lucas',
            birthDate: (new \DateTimeImmutable('-10 years'))->format('Y-m-d'), // mineur
            expiryDate: (new \DateTimeImmutable('+5 years'))->format('Y-m-d'),
            documentId: 'AB9876543',
            mrz: str_repeat('B', 44) . "\n" . str_repeat('B', 44),
            confidenceScore: 92.0,
        ));

        $id = $this->runFullPipeline();

        $body = $this->jsonGet("/api/kyc/{$id}/status");
        self::assertSame('rejected', $body['status']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Pipeline complet → statut approved (happy path)
    // ──────────────────────────────────────────────────────────────────────────

    public function testFullHappyPathLeadsToApprovedStatus(): void
    {
        $id = $this->runFullPipeline();

        $body = $this->jsonGet("/api/kyc/{$id}/status");

        self::assertSame(200, $this->statusCode());
        self::assertSame('approved', $body['status']);
        self::assertSame($id, $body['kycRequestId']);
        self::assertSame('passeport', $body['documentType']);
        self::assertSame(self::APPLICANT_VALID, $body['applicantId']);
        self::assertArrayHasKey('updatedAt', $body);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Audit trail (GET /api/kyc/{id}/audit)
    // ──────────────────────────────────────────────────────────────────────────

    public function testGetAuditTrailReturnsAllEvents(): void
    {
        $id = $this->runFullPipeline();

        $body = $this->jsonGet("/api/kyc/{$id}/audit");

        self::assertSame(200, $this->statusCode());
        self::assertArrayHasKey('entries', $body);

        $entries = $body['entries'];
        self::assertIsArray($entries);
        self::assertCount(4, $entries, 'submit+upload+ocr+validate = 4 événements');

        $types = array_column($entries, 'eventType');
        self::assertContains('kyc_request.submitted', $types);
        self::assertContains('kyc_request.document_uploaded', $types);
        self::assertContains('kyc_request.ocr_extraction_succeeded', $types);
        self::assertContains('kyc_request.approved', $types);
    }

    public function testAuditTrailReturns404ForUnknownId(): void
    {
        $this->jsonGet('/api/kyc/00000000-dead-beef-0000-000000000000/audit');

        self::assertSame(404, $this->statusCode());
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Statut inconnu → 404
    // ──────────────────────────────────────────────────────────────────────────

    public function testGetStatusReturns404ForUnknownId(): void
    {
        $this->jsonGet('/api/kyc/00000000-dead-beef-0000-000000000000/status');

        self::assertSame(404, $this->statusCode());
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Révision manuelle (POST /api/kyc/{id}/manual-review)
    // ──────────────────────────────────────────────────────────────────────────

    public function testManualReviewFlowLeadsToApproved(): void
    {
        // 1. Pipeline complet avec doc expiré → rejected
        $this->ocrStub()->willReturn(new OcrExtractionResult(
            lastName: 'DUPONT',
            firstName: 'Marie',
            birthDate: '1985-06-20',
            expiryDate: '2020-01-01',
            documentId: 'FR1234567',
            mrz: str_repeat('A', 44) . "\n" . str_repeat('A', 44),
            confidenceScore: 95.0,
        ));
        $id = $this->runFullPipeline();

        $statusBody = $this->jsonGet("/api/kyc/{$id}/status");
        self::assertSame('rejected', $statusBody['status']);

        // 2. Demande de révision manuelle
        $this->jsonPost("/api/kyc/{$id}/manual-review", [
            'requestedBy' => 'officer-42',
            'reason'      => 'Document contesté par le demandeur',
        ]);
        self::assertSame(204, $this->statusCode());

        $statusBody = $this->jsonGet("/api/kyc/{$id}/status");
        self::assertSame('under_manual_review', $statusBody['status']);

        // 3. Décision : approuvé
        $this->jsonPost("/api/kyc/{$id}/manual-review/decision", [
            'reviewerId'    => 'officer-42',
            'decision'      => 'approved',
            'justification' => 'Document valide après vérification manuelle',
        ]);
        self::assertSame(204, $this->statusCode());

        $statusBody = $this->jsonGet("/api/kyc/{$id}/status");
        self::assertSame('approved', $statusBody['status']);
    }

    public function testManualReviewOnSubmittedStateReturns422(): void
    {
        $id = $this->submitKyc();

        $this->jsonPost("/api/kyc/{$id}/manual-review", [
            'requestedBy' => 'officer-42',
            'reason'      => 'Test',
        ]);

        self::assertSame(422, $this->statusCode());
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Revues en attente (GET /api/kyc/pending-reviews)
    // ──────────────────────────────────────────────────────────────────────────

    public function testListPendingReviewsIsEmptyInitially(): void
    {
        $body = $this->jsonGet('/api/kyc/pending-reviews');

        self::assertSame(200, $this->statusCode());
        self::assertSame([], $body);
    }

    public function testListPendingReviewsHasItemAfterManualReviewRequest(): void
    {
        // Pipeline complet puis rejet
        $this->ocrStub()->willReturn(new OcrExtractionResult(
            lastName: 'DUPONT',
            firstName: 'Marie',
            birthDate: '1985-06-20',
            expiryDate: '2020-01-01',
            documentId: 'FR1234567',
            mrz: str_repeat('A', 44) . "\n" . str_repeat('A', 44),
            confidenceScore: 95.0,
        ));
        $id = $this->runFullPipeline();

        // Demande de révision
        $this->jsonPost("/api/kyc/{$id}/manual-review", [
            'requestedBy' => 'officer-99',
            'reason'      => 'Document contesté',
        ]);

        $body = $this->jsonGet('/api/kyc/pending-reviews');
        self::assertSame(200, $this->statusCode());
        self::assertCount(1, $body);

        $item = $body[0];
        self::assertSame($id, $item['kycRequestId']);
        self::assertSame('officer-99', $item['requestedBy']);
        self::assertSame('Document contesté', $item['reason']);
        self::assertArrayHasKey('requestedAt', $item);
    }

    public function testMultiplePendingReviewsAreAllListed(): void
    {
        $this->ocrStub()->willReturn(new OcrExtractionResult(
            lastName: 'DOE',
            firstName: 'Jane',
            birthDate: '1990-01-01',
            expiryDate: '2020-01-01',
            documentId: 'AB0000001',
            mrz: str_repeat('C', 44) . "\n" . str_repeat('C', 44),
            confidenceScore: 92.0,
        ));

        $id1 = $this->runFullPipeline(self::APPLICANT_VALID);
        $id2 = $this->runFullPipeline(self::APPLICANT_ALT);

        foreach ([$id1, $id2] as $id) {
            $this->jsonPost("/api/kyc/{$id}/manual-review", [
                'requestedBy' => 'officer-10',
                'reason'      => 'Revue nécessaire',
            ]);
        }

        $body = $this->jsonGet('/api/kyc/pending-reviews');
        self::assertCount(2, $body);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Rapport de décisions (GET /api/kyc/reports/decisions)
    // ──────────────────────────────────────────────────────────────────────────

    public function testGetDecisionReportWithNoDataReturnsZeroCounts(): void
    {
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $body  = $this->jsonGet("/api/kyc/reports/decisions?from={$today}&to={$today}");

        self::assertSame(200, $this->statusCode());
        self::assertSame(0, $body['approvedCount']);
        self::assertSame(0, $body['rejectedCount']);
        self::assertSame(0, $body['approvalRate']);
    }

    public function testGetDecisionReportCountsApprovedAndRejected(): void
    {
        // 2 approuvées (happy path)
        $this->runFullPipeline(self::APPLICANT_VALID);
        $this->runFullPipeline(self::APPLICANT_ALT);

        // 1 rejetée (document expiré)
        $this->ocrStub()->willReturn(new OcrExtractionResult(
            lastName: 'MARTIN',
            firstName: 'Paul',
            birthDate: '1985-01-01',
            expiryDate: '2020-01-01',
            documentId: 'FR9990001',
            mrz: str_repeat('D', 44) . "\n" . str_repeat('D', 44),
            confidenceScore: 93.0,
        ));
        $this->runFullPipeline(self::APPLICANT_THIRD);

        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $body  = $this->jsonGet("/api/kyc/reports/decisions?from={$today}&to={$today}");

        self::assertSame(200, $this->statusCode());
        self::assertSame(2, $body['approvedCount']);
        self::assertSame(1, $body['rejectedCount']);
        self::assertEqualsWithDelta(2 / 3, (float) $body['approvalRate'], 0.01);
    }

    public function testGetDecisionReportWithInvalidDateReturns400(): void
    {
        $this->jsonGet('/api/kyc/reports/decisions?from=not-a-date&to=2024-12-31');

        self::assertSame(400, $this->statusCode());
    }

    public function testGetDecisionReportMissingParamReturns400(): void
    {
        $this->jsonGet('/api/kyc/reports/decisions?from=2024-01-01');

        self::assertSame(400, $this->statusCode());
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers privés
    // ──────────────────────────────────────────────────────────────────────────

    private function ocrStub(): StubOcrAdapter
    {
        $stub = static::getContainer()->get(OcrPort::class);
        assert($stub instanceof StubOcrAdapter);

        return $stub;
    }
}
