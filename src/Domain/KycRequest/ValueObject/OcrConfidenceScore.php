<?php

declare(strict_types=1);

namespace App\Domain\KycRequest\ValueObject;

use App\Domain\KycRequest\Exception\InvalidValueObjectException;

final readonly class OcrConfidenceScore
{
    private function __construct(private float $value) {}

    public static function fromFloat(float $value): self
    {
        if ($value < 0.0 || $value > 100.0) {
            throw new InvalidValueObjectException(
                sprintf('OcrConfidenceScore must be between 0 and 100, got %.2f.', $value)
            );
        }

        return new self($value);
    }

    public function toFloat(): float
    {
        return $this->value;
    }

    public function isAboveThreshold(float $threshold): bool
    {
        return $this->value >= $threshold;
    }
}
