<?php

declare(strict_types=1);

namespace App\Domain\KycRequest\ValueObject;

use App\Domain\KycRequest\Exception\InvalidValueObjectException;

/**
 * Représente la date de naissance d'un demandeur.
 *
 * Règle structurelle : doit être dans le passé.
 * Règle métier (âge ≥ 18 ans) : validée par l'agrégat KycRequest,
 * non par ce VO, afin de garantir la rejouabilité des événements.
 */
final readonly class BirthDate
{
    private function __construct(private \DateTimeImmutable $value)
    {
    }

    public static function fromString(string $date): self
    {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $date);

        if (false === $parsed) {
            throw new InvalidValueObjectException(\sprintf('Invalid BirthDate format: "%s". Expected Y-m-d.', $date));
        }

        $normalized = $parsed->setTime(0, 0, 0);

        if ($normalized >= new \DateTimeImmutable('today')) {
            throw new InvalidValueObjectException('BirthDate must be in the past.');
        }

        return new self($normalized);
    }

    public static function fromDateTimeImmutable(\DateTimeImmutable $date): self
    {
        return self::fromString($date->format('Y-m-d'));
    }

    public function toDateTimeImmutable(): \DateTimeImmutable
    {
        return $this->value;
    }

    public function getAge(): int
    {
        return (int) $this->value->diff(new \DateTimeImmutable('today'))->y;
    }

    public function isAdult(): bool
    {
        return $this->getAge() >= 18;
    }
}
