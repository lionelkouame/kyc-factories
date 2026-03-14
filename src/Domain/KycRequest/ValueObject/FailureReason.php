<?php

declare(strict_types=1);

namespace App\Domain\KycRequest\ValueObject;

use App\Domain\KycRequest\Exception\InvalidValueObjectException;

final readonly class FailureReason
{
    public function __construct(
        public string $code,
        public string $message,
    ) {
        if (trim($this->code) === '') {
            throw new InvalidValueObjectException('FailureReason code cannot be empty.');
        }

        if (trim($this->message) === '') {
            throw new InvalidValueObjectException('FailureReason message cannot be empty.');
        }
    }
}
