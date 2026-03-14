<?php

declare(strict_types=1);

namespace App\Domain\KycRequest\ValueObject;

use App\Domain\KycRequest\Exception\InvalidValueObjectException;

final readonly class DocumentId
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (!preg_match('/^[A-Z0-9]{9,12}$/', $value)) {
            throw new InvalidValueObjectException(\sprintf('Invalid DocumentId: "%s" must be 9–12 uppercase alphanumeric characters.', $value));
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
