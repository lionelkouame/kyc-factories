<?php

declare(strict_types=1);

namespace App\Application\Query\ReadModel;

/**
 * Entrée individuelle dans la piste d'audit d'une demande KYC.
 */
final readonly class AuditTrailEntry
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $eventId,
        public string $eventType,
        public array $payload,
        public \DateTimeImmutable $occurredAt,
        public int $version,
    ) {
    }
}
