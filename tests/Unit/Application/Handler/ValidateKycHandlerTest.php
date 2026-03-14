<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Handler;

use App\Application\Command\ValidateKyc;
use App\Application\Handler\ValidateKycHandler;
use App\Application\Repository\KycRequestRepositoryPort;
use App\Domain\KycRequest\Aggregate\KycRequest;
use App\Domain\KycRequest\Aggregate\KycStatus;
use App\Domain\KycRequest\Event\KycApproved;
use App\Domain\KycRequest\Event\KycRejected;
use App\Domain\KycRequest\Port\DomainEventPublisherPort;
use App\Domain\KycRequest\ValueObject\ApplicantId;
use App\Domain\KycRequest\ValueObject\BlurVarianceScore;
use App\Domain\KycRequest\ValueObject\DocumentType;
use App\Domain\KycRequest\ValueObject\KycRequestId;
use App\Domain\KycRequest\ValueObject\OcrConfidenceScore;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ValidateKycHandlerTest extends TestCase
{
    private KycRequestRepositoryPort&MockObject $repository;
    private DomainEventPublisherPort&MockObject $publisher;
    private ValidateKycHandler $handler;

    private KycRequestId $id;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(KycRequestRepositoryPort::class);
        $this->publisher = $this->createMock(DomainEventPublisherPort::class);
        $this->handler = new ValidateKycHandler($this->repository, $this->publisher);

        $this->id = KycRequestId::generate();
    }

    private function buildOcrCompletedRequest(
        ?string $lastName = 'DUPONT',
        ?string $firstName = 'Jean',
        ?string $birthDate = '1990-06-15',
        ?string $expiryDate = null,
        ?string $documentId = 'FR123456789',
        ?string $mrz = null,
        float $confidenceScore = 85.0,
    ): KycRequest {
        if (null === $expiryDate) {
            $expiryDate = (new \DateTimeImmutable('+5 years'))->format('Y-m-d');
        }

        if (null === $mrz) {
            // MRZ TD1: 2 lignes de 30 chars
            $mrz = str_pad('IDFRADUPONT<<JEAN<<<<<<<<<<<<', 30, '<')."\n".str_pad('FR123456789', 30, '<');
        }

        $e1 = new \App\Domain\KycRequest\Event\KycRequestSubmitted(
            $this->id,
            ApplicantId::fromString('550e8400-e29b-41d4-a716-446655440000'),
            DocumentType::Cni,
        );
        $e1->version = 1;

        $e2 = new \App\Domain\KycRequest\Event\DocumentUploaded(
            $this->id,
            'docs/test.jpg',
            'image/jpeg',
            1_000_000,
            300.0,
            BlurVarianceScore::fromFloat(120.0),
            'sha256',
        );
        $e2->version = 2;

        $e3 = new \App\Domain\KycRequest\Event\OcrExtractionSucceeded(
            $this->id,
            $lastName,
            $firstName,
            $birthDate,
            $expiryDate,
            $documentId,
            $mrz,
            OcrConfidenceScore::fromFloat($confidenceScore),
        );
        $e3->version = 3;

        return KycRequest::reconstitute([$e1, $e2, $e3]);
    }

    // ── Happy path ───────────────────────────────────────────────────────────

    public function testAllRulesPassProducesKycApprovedEvent(): void
    {
        $this->repository->method('get')->willReturn($this->buildOcrCompletedRequest());

        $publishedEvents = null;
        $this->publisher->method('publishAll')
            ->willReturnCallback(function (array $events) use (&$publishedEvents): void {
                $publishedEvents = $events;
            });

        $this->handler->handle(new ValidateKyc($this->id->toString()));

        self::assertCount(1, $publishedEvents ?? []);
        self::assertInstanceOf(KycApproved::class, ($publishedEvents ?? [])[0]);
    }

    public function testApprovedSetsStatusToApproved(): void
    {
        $this->repository->method('get')->willReturn($this->buildOcrCompletedRequest());

        $capturedAggregate = null;
        $this->repository->method('save')
            ->willReturnCallback(function (KycRequest $agg) use (&$capturedAggregate): void {
                $capturedAggregate = $agg;
            });

        $this->handler->handle(new ValidateKyc($this->id->toString()));

        self::assertSame(KycStatus::Approved, $capturedAggregate?->getStatus());
    }

    // ── Bloquant : UNDERAGE_APPLICANT ─────────────────────────────────────────

    public function testUnderageApplicantProducesKycRejectedWithE_VAL_UNDERAGE(): void
    {
        $underageBirthDate = (new \DateTimeImmutable('-16 years'))->format('Y-m-d');
        $this->repository->method('get')->willReturn(
            $this->buildOcrCompletedRequest(birthDate: $underageBirthDate),
        );

        $publishedEvents = null;
        $this->publisher->method('publishAll')
            ->willReturnCallback(function (array $events) use (&$publishedEvents): void {
                $publishedEvents = $events;
            });

        $this->handler->handle(new ValidateKyc($this->id->toString()));

        self::assertInstanceOf(KycRejected::class, ($publishedEvents ?? [])[0]);
        $reasons = ($publishedEvents ?? [])[0]->failureReasons;
        self::assertCount(1, $reasons);
        self::assertSame('E_VAL_UNDERAGE', $reasons[0]->code);
    }

    public function testNullBirthDateProducesE_VAL_UNDERAGE(): void
    {
        $this->repository->method('get')->willReturn(
            $this->buildOcrCompletedRequest(birthDate: null),
        );

        $publishedEvents = null;
        $this->publisher->method('publishAll')
            ->willReturnCallback(function (array $events) use (&$publishedEvents): void {
                $publishedEvents = $events;
            });

        $this->handler->handle(new ValidateKyc($this->id->toString()));

        self::assertSame('E_VAL_UNDERAGE', ($publishedEvents ?? [])[0]->failureReasons[0]->code);
    }

    // ── Bloquant : DOCUMENT_EXPIRED ──────────────────────────────────────────

    public function testExpiredDocumentProducesKycRejectedWithE_VAL_EXPIRED(): void
    {
        $this->repository->method('get')->willReturn(
            $this->buildOcrCompletedRequest(expiryDate: '2020-01-01'),
        );

        $publishedEvents = null;
        $this->publisher->method('publishAll')
            ->willReturnCallback(function (array $events) use (&$publishedEvents): void {
                $publishedEvents = $events;
            });

        $this->handler->handle(new ValidateKyc($this->id->toString()));

        self::assertInstanceOf(KycRejected::class, ($publishedEvents ?? [])[0]);
        self::assertSame('E_VAL_EXPIRED', ($publishedEvents ?? [])[0]->failureReasons[0]->code);
    }

    // ── Non bloquant : nom invalide ───────────────────────────────────────────

    public function testInvalidLastNameProducesRejectionWithE_VAL_NAME(): void
    {
        $this->repository->method('get')->willReturn(
            $this->buildOcrCompletedRequest(lastName: 'X'), // < 2 chars
        );

        $publishedEvents = null;
        $this->publisher->method('publishAll')
            ->willReturnCallback(function (array $events) use (&$publishedEvents): void {
                $publishedEvents = $events;
            });

        $this->handler->handle(new ValidateKyc($this->id->toString()));

        $codes = array_map(fn ($r) => $r->code, ($publishedEvents ?? [])[0]->failureReasons ?? []);
        self::assertContains('E_VAL_NAME', $codes);
    }

    // ── Non bloquant : documentId invalide ───────────────────────────────────

    public function testInvalidDocumentIdProducesRejectionWithE_VAL_DOC_ID(): void
    {
        $this->repository->method('get')->willReturn(
            $this->buildOcrCompletedRequest(documentId: 'AB'), // trop court
        );

        $publishedEvents = null;
        $this->publisher->method('publishAll')
            ->willReturnCallback(function (array $events) use (&$publishedEvents): void {
                $publishedEvents = $events;
            });

        $this->handler->handle(new ValidateKyc($this->id->toString()));

        $codes = array_map(fn ($r) => $r->code, ($publishedEvents ?? [])[0]->failureReasons ?? []);
        self::assertContains('E_VAL_DOC_ID', $codes);
    }

    // ── Non bloquant : MRZ invalide ───────────────────────────────────────────

    public function testInvalidMrzProducesRejectionWithE_VAL_MRZ(): void
    {
        $this->repository->method('get')->willReturn(
            $this->buildOcrCompletedRequest(mrz: 'INVALID_MRZ'),
        );

        $publishedEvents = null;
        $this->publisher->method('publishAll')
            ->willReturnCallback(function (array $events) use (&$publishedEvents): void {
                $publishedEvents = $events;
            });

        $this->handler->handle(new ValidateKyc($this->id->toString()));

        $codes = array_map(fn ($r) => $r->code, ($publishedEvents ?? [])[0]->failureReasons ?? []);
        self::assertContains('E_VAL_MRZ', $codes);
    }

    // ── Collecte de multiples violations ─────────────────────────────────────

    public function testMultipleViolationsAreAllCollected(): void
    {
        $this->repository->method('get')->willReturn(
            $this->buildOcrCompletedRequest(
                lastName: 'X',          // invalide
                documentId: 'AB',       // invalide
                mrz: 'BAD_MRZ',        // invalide
            ),
        );

        $publishedEvents = null;
        $this->publisher->method('publishAll')
            ->willReturnCallback(function (array $events) use (&$publishedEvents): void {
                $publishedEvents = $events;
            });

        $this->handler->handle(new ValidateKyc($this->id->toString()));

        $reasons = ($publishedEvents ?? [])[0]->failureReasons ?? [];
        self::assertGreaterThanOrEqual(3, \count($reasons));
    }

    // ── Les bloquants interrompent la collecte ────────────────────────────────

    public function testUnderageBlocksCollectionOfOtherViolations(): void
    {
        $underageBirthDate = (new \DateTimeImmutable('-16 years'))->format('Y-m-d');
        $this->repository->method('get')->willReturn(
            $this->buildOcrCompletedRequest(
                birthDate: $underageBirthDate,
                lastName: 'X',       // violation non bloquante
                documentId: 'AB',   // violation non bloquante
            ),
        );

        $publishedEvents = null;
        $this->publisher->method('publishAll')
            ->willReturnCallback(function (array $events) use (&$publishedEvents): void {
                $publishedEvents = $events;
            });

        $this->handler->handle(new ValidateKyc($this->id->toString()));

        $reasons = ($publishedEvents ?? [])[0]->failureReasons ?? [];
        // Seul E_VAL_UNDERAGE — les autres sont ignorées (court-circuit)
        self::assertCount(1, $reasons);
        self::assertSame('E_VAL_UNDERAGE', $reasons[0]->code);
    }
}
