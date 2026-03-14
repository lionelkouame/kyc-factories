<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\ValueObject;

use App\Domain\KycRequest\Exception\InvalidValueObjectException;
use App\Domain\KycRequest\ValueObject\ExpiryDate;
use PHPUnit\Framework\TestCase;

final class ExpiryDateTest extends TestCase
{
    public function testFutureDateIsNotExpired(): void
    {
        $expiryDate = ExpiryDate::fromString(
            (new \DateTimeImmutable('+5 years'))->format('Y-m-d')
        );

        self::assertFalse($expiryDate->isExpired());
    }

    public function testPastDateIsExpired(): void
    {
        $expiryDate = ExpiryDate::fromString('2020-01-01');

        self::assertTrue($expiryDate->isExpired());
    }

    public function testInvalidFormatThrows(): void
    {
        $this->expectException(InvalidValueObjectException::class);

        ExpiryDate::fromString('01/01/2030');
    }

    public function testRoundtripPreservesDate(): void
    {
        $expiryDate = ExpiryDate::fromString('2030-12-31');

        self::assertSame('2030-12-31', $expiryDate->toDateTimeImmutable()->format('Y-m-d'));
    }
}
