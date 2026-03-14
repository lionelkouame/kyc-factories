<?php

declare(strict_types=1);

namespace App\Domain\KycRequest\ValueObject;

use App\Domain\KycRequest\Exception\InvalidValueObjectException;

final readonly class MrzCode
{
    private function __construct(private string $value) {}

    public static function fromString(string $value): self
    {
        $lines = explode("\n", trim($value));

        if (count($lines) !== 2) {
            throw new InvalidValueObjectException(
                sprintf('MRZ must contain exactly 2 lines, got %d.', count($lines))
            );
        }

        foreach ($lines as $line) {
            $len = strlen($line);
            if ($len !== 30 && $len !== 44) {
                throw new InvalidValueObjectException(
                    sprintf('Each MRZ line must be 30 (TD1) or 44 (TD3) characters, got %d.', $len)
                );
            }

            if (!preg_match('/^[A-Z0-9<]+$/', $line)) {
                throw new InvalidValueObjectException(
                    'MRZ lines must contain only uppercase letters, digits and "<".'
                );
            }
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
