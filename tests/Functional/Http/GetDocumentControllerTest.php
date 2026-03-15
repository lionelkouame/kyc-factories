<?php

declare(strict_types=1);

namespace App\Tests\Functional\Http;

use App\Application\Repository\KycRequestRepositoryPort;
use App\Domain\KycRequest\Aggregate\KycRequest;
use App\Domain\KycRequest\Event\DocumentUploaded;
use App\Domain\KycRequest\Event\KycApproved;
use App\Domain\KycRequest\Event\KycRequestSubmitted;
use App\Domain\KycRequest\Event\OcrExtractionSucceeded;
use App\Domain\KycRequest\Port\DocumentStoragePort;
use App\Domain\KycRequest\ValueObject\ApplicantId;
use App\Domain\KycRequest\ValueObject\BlurVarianceScore;
use App\Domain\KycRequest\ValueObject\DocumentType;
use App\Domain\KycRequest\ValueObject\KycRequestId;
use App\Domain\KycRequest\ValueObject\OcrConfidenceScore;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Tests fonctionnels — US-16 : accès streamé et authentifié aux fichiers.
 *
 * Critères d'acceptance :
 * - Accès sans authentification → 401
 * - Accès avec mauvais rôle → 403
 * - Accès valide (ROLE_KYC_AUDITOR) → 200 avec headers de sécurité
 */
final class GetDocumentControllerTest extends WebTestCase
{
    private const STORAGE_PATH   = 'documents/test-id/abc123.jpg';
    public const FILE_CONTENT   = 'fake-image-binary-content';
    private const KYC_REQUEST_ID = '550e8400-e29b-41d4-a716-446655440000';

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function buildApprovedRequest(KycRequestId $id): KycRequest
    {
        $e1 = new KycRequestSubmitted($id, ApplicantId::fromString('550e8400-e29b-41d4-a716-446655440001'), DocumentType::Cni);
        $e1->version = 1;

        $e2 = new DocumentUploaded($id, self::STORAGE_PATH, 'image/jpeg', 1_000_000, 300.0, BlurVarianceScore::fromFloat(120.0), 'sha256abc');
        $e2->version = 2;

        $mrz = str_pad('IDFRADUPONT', 30, '<') . "\n" . str_pad('FR123456789', 30, '<');
        $e3  = new OcrExtractionSucceeded($id, 'DUPONT', 'Jean', '1990-06-15', '2030-01-01', 'FR123456789', $mrz, OcrConfidenceScore::fromFloat(90.0));
        $e3->version = 3;

        $e4 = new KycApproved($id);
        $e4->version = 4;

        return KycRequest::reconstitute([$e1, $e2, $e3, $e4]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 401 — Unauthenticated
    // ──────────────────────────────────────────────────────────────────────────

    public function testUnauthenticatedRequestReturns401(): void
    {
        $client = static::createClient();

        $client->request('GET', sprintf('/api/kyc/%s/document', self::KYC_REQUEST_ID));

        self::assertResponseStatusCodeSame(401);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 403 — Wrong role
    // ──────────────────────────────────────────────────────────────────────────

    public function testWrongRoleReturns403(): void
    {
        $client = static::createClient();
        $client->loginUser(new InMemoryUser('basic_user', null, ['ROLE_USER']));

        $client->request('GET', sprintf('/api/kyc/%s/document', self::KYC_REQUEST_ID));

        self::assertResponseStatusCodeSame(403);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 200 — Successful streaming
    // ──────────────────────────────────────────────────────────────────────────

    public function testAuthorizedRequestReturns200WithSecurityHeaders(): void
    {
        $client = static::createClient();

        // Override services in the test container
        $container = static::getContainer();

        $id      = KycRequestId::fromString(self::KYC_REQUEST_ID);
        $request = $this->buildApprovedRequest($id);

        $stubRepository = new class($request) implements KycRequestRepositoryPort {
            public function __construct(private readonly KycRequest $kyc) {}

            public function get(\App\Domain\KycRequest\ValueObject\KycRequestId $id): KycRequest
            {
                return $this->kyc;
            }

            public function save(KycRequest $kycRequest): void {}
        };

        $stubStorage = new class implements DocumentStoragePort {
            public function store(string $kycRequestId, string $fileContent, string $mimeType): string
            {
                return 'path';
            }

            public function retrieve(string $storagePath): string
            {
                return GetDocumentControllerTest::FILE_CONTENT;
            }

            public function delete(string $storagePath): void {}
        };

        $container->set(KycRequestRepositoryPort::class, $stubRepository);
        $container->set(DocumentStoragePort::class, $stubStorage);

        $client->loginUser(new InMemoryUser('kyc_auditor', null, ['ROLE_KYC_AUDITOR']));
        $client->request('GET', sprintf('/api/kyc/%s/document', self::KYC_REQUEST_ID));

        self::assertResponseIsSuccessful();

        // Content was captured via filterResponse (StreamedResponse already sent)
        self::assertSame(self::FILE_CONTENT, $client->getInternalResponse()->getContent());

        // Security headers
        self::assertResponseHasHeader('Content-Disposition');
        self::assertStringContainsString(
            'attachment',
            (string) $client->getResponse()->headers->get('Content-Disposition'),
        );
        self::assertResponseHasHeader('X-Content-Type-Options');
        self::assertSame(
            'nosniff',
            $client->getResponse()->headers->get('X-Content-Type-Options'),
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 404 — Document not found / purged
    // ──────────────────────────────────────────────────────────────────────────

    public function testPurgedDocumentReturns404(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        $id = KycRequestId::fromString(self::KYC_REQUEST_ID);

        // Build a request where document has been purged
        $e1 = new KycRequestSubmitted($id, ApplicantId::fromString('550e8400-e29b-41d4-a716-446655440001'), DocumentType::Cni);
        $e1->version = 1;
        $e2 = new DocumentUploaded($id, self::STORAGE_PATH, 'image/jpeg', 1_000_000, 300.0, BlurVarianceScore::fromFloat(120.0), 'sha256abc');
        $e2->version = 2;
        $mrz = str_pad('IDFRADUPONT', 30, '<') . "\n" . str_pad('FR123456789', 30, '<');
        $e3  = new OcrExtractionSucceeded($id, 'DUPONT', 'Jean', '1990-06-15', '2030-01-01', 'FR123456789', $mrz, OcrConfidenceScore::fromFloat(90.0));
        $e3->version = 3;
        $e4 = new KycApproved($id);
        $e4->version = 4;
        $e5 = new \App\Domain\KycRequest\Event\DocumentPurged($id);
        $e5->version = 5;

        $purgedRequest = KycRequest::reconstitute([$e1, $e2, $e3, $e4, $e5]);

        $stubRepository = new class($purgedRequest) implements KycRequestRepositoryPort {
            public function __construct(private readonly KycRequest $kyc) {}

            public function get(\App\Domain\KycRequest\ValueObject\KycRequestId $id): KycRequest
            {
                return $this->kyc;
            }

            public function save(KycRequest $kycRequest): void {}
        };

        $container->set(KycRequestRepositoryPort::class, $stubRepository);

        $client->loginUser(new InMemoryUser('kyc_auditor', null, ['ROLE_KYC_AUDITOR']));
        $client->request('GET', sprintf('/api/kyc/%s/document', self::KYC_REQUEST_ID));

        self::assertResponseStatusCodeSame(404);
    }
}
