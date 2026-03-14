<?php

declare(strict_types=1);

namespace App\Domain\KycRequest\ValueObject;

use App\Domain\KycRequest\Exception\InvalidValueObjectException;

/**
 * Représente la date d'expiration d'un document d'identité.
 *
 * Règle structurelle : doit être une date valide.
 * Règle métier (document non expiré) : validée par l'agrégat KycRequest,
 * non par ce VO, afin de garantir la rejouabilité des événements.
 */
final readonly class ExpiryDate
{
    private function __construct(private \DateTimeImmutable $value)
    {
    }

    public static function fromString(string $date): self
    {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $date);

        if (false === $parsed) {
            throw new InvalidValueObjectException(\sprintf('Invalid ExpiryDate format: "%s". Expected Y-m-d.', $date));
        }

        return new self($parsed->setTime(23, 59, 59));
    }

    public static function fromDateTimeImmutable(\DateTimeImmutable $date): self
    {
        return self::fromString($date->format('Y-m-d'));
    }

    public function toDateTimeImmutable(): \DateTimeImmutable
    {
        return $this->value;
    }

    public function isExpired(): bool
    {
        return $this->value < new \DateTimeImmutable('today');
    }
}
