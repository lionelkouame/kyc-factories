<?php

declare(strict_types=1);

namespace App\Domain\KycRequest\ValueObject;

use App\Domain\KycRequest\Exception\InvalidValueObjectException;
use Symfony\Component\Uid\Uuid;

final readonly class ApplicantId
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (!Uuid::isValid($value)) {
            throw new InvalidValueObjectException(\sprintf('Invalid ApplicantId: "%s" is not a valid UUID.', $value));
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
