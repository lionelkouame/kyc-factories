<?php

declare(strict_types=1);

namespace App\Tests\Functional\Http;

use App\Application\Projection\KycAuditTrailProjectorPort;
use App\Application\Projection\KycDecisionReportProjectorPort;
use App\Application\Projection\KycRequestStatusProjectorPort;
use App\Application\Projection\PendingManualReviewProjectorPort;
use App\Domain\KycRequest\Port\OcrPort;
use App\Infrastructure\Ocr\StubOcrAdapter;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Classe de base pour les tests d'intégration HTTP du pipeline KYC.
 *
 * Pour chaque test :
 *  - Un client HTTP Symfony est créé (noyau rechargé).
 *  - La table kyc_events est créée si elle n'existe pas, puis purgée.
 *  - Tous les projecteurs in-memory sont réinitialisés.
 *  - L'adaptateur OCR stub est réinitialisé (comportement par défaut = dossier valide).
 *
 * Le publisher utilisé en env=test est ProjectionForwardingEventPublisher, ce qui
 * garantit que les projecteurs sont mis à jour après chaque commande d'écriture.
 */
abstract class AbstractKycHttpTestCase extends WebTestCase
{
    private const SCHEMA_SQL = <<<'SQL'
        CREATE TABLE IF NOT EXISTS kyc_events (
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
    SQL;

    protected KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        // Disable kernel reboot between requests so in-memory services
        // (document storage, projectors) persist across multiple HTTP calls within one test.
        $this->client->disableReboot();
        $container    = static::getContainer();

        /** @var Connection $conn */
        $conn = $container->get('doctrine.dbal.default_connection');
        $conn->executeStatement(self::SCHEMA_SQL);
        $conn->executeStatement('DELETE FROM kyc_events');

        $container->get(KycRequestStatusProjectorPort::class)->reset();
        $container->get(KycAuditTrailProjectorPort::class)->reset();
        $container->get(PendingManualReviewProjectorPort::class)->reset();
        $container->get(KycDecisionReportProjectorPort::class)->reset();

        // Reset the OCR stub to its default valid result
        $stub = $container->get(OcrPort::class);
        assert($stub instanceof StubOcrAdapter);
        $stub->reset();
    }

    // ── HTTP helpers ──────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    protected function jsonPost(string $uri, ?array $body = null): array
    {
        $this->client->request(
            'POST',
            $uri,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $body !== null ? (string) json_encode($body) : '',
        );

        return $this->decodeResponse();
    }

    /** @return array<string, mixed> */
    protected function jsonGet(string $uri): array
    {
        $this->client->request('GET', $uri);

        return $this->decodeResponse();
    }

    protected function statusCode(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    /** @return array<string, mixed> */
    private function decodeResponse(): array
    {
        $content = $this->client->getResponse()->getContent();

        if ($content === '' || $content === false) {
            return [];
        }

        $decoded = json_decode($content, true);

        return \is_array($decoded) ? $decoded : [];
    }

    // ── Pipeline helpers ──────────────────────────────────────────────────────

    /**
     * Soumet une demande KYC et retourne le kycRequestId généré.
     */
    protected function submitKyc(
        string $applicantId = 'a1b2c3d4-e5f6-4789-8012-3456789abcde',
        string $documentType = 'passeport',
    ): string {
        $body = $this->jsonPost('/api/kyc', [
            'applicantId'  => $applicantId,
            'documentType' => $documentType,
        ]);

        self::assertSame(201, $this->statusCode(), 'submit should return 201');

        return (string) ($body['kycRequestId'] ?? '');
    }

    /**
     * Upload un document valide (sharp, bonne résolution, format JPEG).
     */
    protected function uploadValidDocument(string $kycRequestId, float $blurVariance = 150.0): void
    {
        $rawContent = 'fake-jpeg-content-' . $kycRequestId;
        $this->jsonPost("/api/kyc/{$kycRequestId}/document", [
            'fileContent'  => base64_encode($rawContent),
            'mimeType'     => 'image/jpeg',
            'sizeBytes'    => 500_000,
            'dpi'          => 300.0,
            'blurVariance' => $blurVariance,
            'sha256Hash'   => hash('sha256', $rawContent),
        ]);

        self::assertSame(204, $this->statusCode(), 'upload should return 204');
    }

    protected function runOcr(string $kycRequestId): void
    {
        $this->jsonPost("/api/kyc/{$kycRequestId}/ocr");
        self::assertSame(204, $this->statusCode(), 'ocr should return 204');
    }

    protected function validate(string $kycRequestId): void
    {
        $this->jsonPost("/api/kyc/{$kycRequestId}/validate");
        self::assertSame(204, $this->statusCode(), 'validate should return 204');
    }

    /**
     * Pipeline complet submit → upload → ocr → validate.
     */
    protected function runFullPipeline(
        string $applicantId = 'a1b2c3d4-e5f6-4789-8012-3456789abcde',
        string $documentType = 'passeport',
    ): string {
        $id = $this->submitKyc($applicantId, $documentType);
        $this->uploadValidDocument($id);
        $this->runOcr($id);
        $this->validate($id);

        return $id;
    }
}
