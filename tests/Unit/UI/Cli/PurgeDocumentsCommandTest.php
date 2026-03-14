<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Cli;

use App\Application\Projection\KycRequestStatusProjectorPort;
use App\Application\Query\ReadModel\KycRequestStatusView;
use App\Application\Repository\KycRequestRepositoryPort;
use App\Domain\KycRequest\Aggregate\KycRequest;
use App\Domain\KycRequest\Event\DocumentPurged;
use App\Domain\KycRequest\Event\DocumentUploaded;
use App\Domain\KycRequest\Event\KycApproved;
use App\Domain\KycRequest\Event\KycRequestSubmitted;
use App\Domain\KycRequest\Event\OcrExtractionSucceeded;
use App\Domain\KycRequest\Port\DocumentStoragePort;
use App\Domain\KycRequest\Port\DomainEventPublisherPort;
use App\Domain\KycRequest\ValueObject\ApplicantId;
use App\Domain\KycRequest\ValueObject\BlurVarianceScore;
use App\Domain\KycRequest\ValueObject\DocumentType;
use App\Domain\KycRequest\ValueObject\KycRequestId;
use App\Domain\KycRequest\ValueObject\OcrConfidenceScore;
use App\UI\Cli\PurgeDocumentsCommand;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests unitaires de PurgeDocumentsCommand.
 *
 * Critères d'acceptance US-15 :
 * - Le fichier est bien supprimé du disque (DocumentStoragePort::delete())
 * - DocumentPurged est enregistré dans l'event store (via repository.save + publisher)
 * - Idempotent : double exécution → aucun effet de bord
 */
final class PurgeDocumentsCommandTest extends TestCase
{
    private KycRequestStatusProjectorPort&MockObject $statusProjector;
    private KycRequestRepositoryPort&MockObject $repository;
    private DocumentStoragePort&MockObject $storage;
    private DomainEventPublisherPort&MockObject $publisher;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->statusProjector = $this->createMock(KycRequestStatusProjectorPort::class);
        $this->repository      = $this->createMock(KycRequestRepositoryPort::class);
        $this->storage         = $this->createMock(DocumentStoragePort::class);
        $this->publisher       = $this->createMock(DomainEventPublisherPort::class);

        $command      = new PurgeDocumentsCommand(
            $this->statusProjector,
            $this->repository,
            $this->storage,
            $this->publisher,
        );
        $this->tester = new CommandTester($command);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function buildApprovedRequest(KycRequestId $id, string $storagePath = 'docs/id.jpg'): KycRequest
    {
        $e1 = new KycRequestSubmitted($id, ApplicantId::fromString('550e8400-e29b-41d4-a716-446655440000'), DocumentType::Cni);
        $e1->version = 1;

        $e2 = new DocumentUploaded($id, $storagePath, 'image/jpeg', 1_000_000, 300.0, BlurVarianceScore::fromFloat(120.0), 'sha256abc');
        $e2->version = 2;

        $mrz = str_pad('IDFRADUPONT', 30, '<') . "\n" . str_pad('FR123456789', 30, '<');
        $e3  = new OcrExtractionSucceeded($id, 'DUPONT', 'Jean', '1990-06-15', '2030-01-01', 'FR123456789', $mrz, OcrConfidenceScore::fromFloat(90.0));
        $e3->version = 3;

        $e4 = new KycApproved($id);
        $e4->version = 4;

        return KycRequest::reconstitute([$e1, $e2, $e3, $e4]);
    }

    private function buildAlreadyPurgedRequest(KycRequestId $id): KycRequest
    {
        $request = $this->buildApprovedRequest($id);

        $e5 = new DocumentPurged($id);
        $e5->version = 5;

        return KycRequest::reconstitute([
            ...iterator_to_array($this->extractEventsViaReconstitute($id)),
            $e5,
        ]);
    }

    /**
     * Build a reconstituted request with DocumentPurged applied.
     */
    private function buildAlreadyPurgedRequestDirect(KycRequestId $id): KycRequest
    {
        $e1 = new KycRequestSubmitted($id, ApplicantId::fromString('550e8400-e29b-41d4-a716-446655440000'), DocumentType::Cni);
        $e1->version = 1;

        $e2 = new DocumentUploaded($id, 'docs/id.jpg', 'image/jpeg', 1_000_000, 300.0, BlurVarianceScore::fromFloat(120.0), 'sha256abc');
        $e2->version = 2;

        $mrz = str_pad('IDFRADUPONT', 30, '<') . "\n" . str_pad('FR123456789', 30, '<');
        $e3  = new OcrExtractionSucceeded($id, 'DUPONT', 'Jean', '1990-06-15', '2030-01-01', 'FR123456789', $mrz, OcrConfidenceScore::fromFloat(90.0));
        $e3->version = 3;

        $e4 = new KycApproved($id);
        $e4->version = 4;

        $e5 = new DocumentPurged($id);
        $e5->version = 5;

        return KycRequest::reconstitute([$e1, $e2, $e3, $e4, $e5]);
    }

    private function makeStatusView(KycRequestId $id, string $status, \DateTimeImmutable $updatedAt): KycRequestStatusView
    {
        return new KycRequestStatusView($id->toString(), 'applicant-uuid', 'cni', $status, $updatedAt);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Tests
    // ──────────────────────────────────────────────────────────────────────────

    public function testNoCandidatesSucceedsWithMessage(): void
    {
        $this->statusProjector->method('findTerminalOlderThan')->willReturn([]);

        $this->tester->execute([]);

        self::assertSame(0, $this->tester->getStatusCode());
        self::assertStringContainsString('Aucune demande à purger', $this->tester->getDisplay());
    }

    public function testCandidatePurgedDeletesFileAndPublishesEvent(): void
    {
        $id      = KycRequestId::generate();
        $request = $this->buildApprovedRequest($id, 'docs/id.jpg');
        $view    = $this->makeStatusView($id, 'approved', new \DateTimeImmutable('-31 days'));

        $this->statusProjector->method('findTerminalOlderThan')->willReturn([$view]);
        $this->repository->method('get')->willReturn($request);

        $this->storage->expects(self::once())->method('delete')->with('docs/id.jpg');
        $this->repository->expects(self::once())->method('save');
        $this->publisher->expects(self::once())->method('publishAll')
            ->with(self::callback(static function (array $events): bool {
                return \count($events) === 1 && $events[0] instanceof DocumentPurged;
            }));

        $this->tester->execute([]);

        self::assertSame(0, $this->tester->getStatusCode());
    }

    public function testAlreadyPurgedRequestIsSkippedIdempotently(): void
    {
        $id      = KycRequestId::generate();
        $request = $this->buildAlreadyPurgedRequestDirect($id);
        $view    = $this->makeStatusView($id, 'approved', new \DateTimeImmutable('-40 days'));

        $this->statusProjector->method('findTerminalOlderThan')->willReturn([$view]);
        $this->repository->method('get')->willReturn($request);

        $this->storage->expects(self::never())->method('delete');
        $this->repository->expects(self::never())->method('save');
        $this->publisher->expects(self::never())->method('publishAll');

        $this->tester->execute([]);

        self::assertSame(0, $this->tester->getStatusCode());
    }

    public function testCustomRetentionDaysOptionIsRespected(): void
    {
        $capturedBefore = null;

        $this->statusProjector
            ->expects(self::once())
            ->method('findTerminalOlderThan')
            ->willReturnCallback(function (\DateTimeImmutable $before) use (&$capturedBefore): array {
                $capturedBefore = $before;

                return [];
            });

        $this->tester->execute(['--days' => '60']);

        self::assertNotNull($capturedBefore);
        /** @var \DateTimeImmutable $capturedBefore */
        $diff = (new \DateTimeImmutable())->diff($capturedBefore);
        self::assertEqualsWithDelta(60, (int) $diff->format('%a'), 1);
    }

    public function testInvalidDaysOptionReturnsFailure(): void
    {
        $this->tester->execute(['--days' => '0']);

        self::assertSame(1, $this->tester->getStatusCode());
    }
}
