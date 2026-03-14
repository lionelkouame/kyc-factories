<?php

declare(strict_types=1);

namespace App\Domain\KycRequest\Port;

/**
 * Exception levée par OcrPort lorsque l'extraction ne peut pas aboutir.
 * Le failureCode correspond aux codes du catalogue (E_OCR_TIMEOUT, E_OCR_CORRUPT…).
 */
final class OcrExtractionException extends \RuntimeException
{
    public function __construct(
        private readonly string $failureCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getFailureCode(): string
    {
        return $this->failureCode;
    }
}
