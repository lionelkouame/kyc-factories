<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Bus;

use App\Infrastructure\Bus\SymfonyMessengerCommandBus;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class SymfonyMessengerCommandBusTest extends TestCase
{
    private MessageBusInterface&MockObject $messageBus;
    private SymfonyMessengerCommandBus $bus;

    protected function setUp(): void
    {
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->bus        = new SymfonyMessengerCommandBus($this->messageBus);
    }

    // ── Dispatch nominal ─────────────────────────────────────────────────────

    public function testDispatchDelegatesToMessageBus(): void
    {
        $command = new \stdClass();

        $this->messageBus
            ->expects(self::once())
            ->method('dispatch')
            ->with($command)
            ->willReturn(new Envelope($command));

        $this->bus->dispatch($command);
    }

    public function testDispatchDoesNotThrowOnSuccess(): void
    {
        $command = new \stdClass();

        $this->messageBus
            ->method('dispatch')
            ->willReturn(new Envelope($command));

        $this->bus->dispatch($command);
        $this->addToAssertionCount(1);
    }

    // ── Désenveloppement de HandlerFailedException ───────────────────────────

    public function testDispatchUnwrapsHandlerFailedExceptionToOriginalCause(): void
    {
        $command           = new \stdClass();
        $originalException = new \RuntimeException('Handler failure.');
        $wrapped           = new HandlerFailedException(new Envelope($command), [$originalException]);

        $this->messageBus
            ->method('dispatch')
            ->willThrowException($wrapped);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Handler failure.');

        $this->bus->dispatch($command);
    }

    public function testDispatchUnwrapsKycDomainException(): void
    {
        $command   = new \stdClass();
        $domainEx  = new \App\Domain\KycRequest\Exception\InvalidTransitionException('Transition invalide.');
        $wrapped   = new HandlerFailedException(new Envelope($command), [$domainEx]);

        $this->messageBus
            ->method('dispatch')
            ->willThrowException($wrapped);

        $this->expectException(\App\Domain\KycRequest\Exception\KycDomainException::class);

        $this->bus->dispatch($command);
    }
}
