<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\ValueObject;

use App\Domain\KycRequest\Exception\InvalidValueObjectException;
use App\Domain\KycRequest\ValueObject\BirthDate;
use PHPUnit\Framework\TestCase;

final class BirthDateTest extends TestCase
{
    public function testAdultIsAdult(): void
    {
        $birthDate = BirthDate::fromString('1990-06-15');

        self::assertTrue($birthDate->isAdult());
    }

    public function testMinorIsNotAdult(): void
    {
        $minorDate = (new \DateTimeImmutable('today'))->modify('-16 years')->format('Y-m-d');
        $birthDate = BirthDate::fromString($minorDate);

        self::assertFalse($birthDate->isAdult());
    }

    public function testExactly18IsAdult(): void
    {
        $date = (new \DateTimeImmutable('today'))->modify('-18 years')->format('Y-m-d');
        $birthDate = BirthDate::fromString($date);

        self::assertTrue($birthDate->isAdult());
    }

    public function testFutureDateThrows(): void
    {
        $this->expectException(InvalidValueObjectException::class);

        BirthDate::fromString((new \DateTimeImmutable('+1 day'))->format('Y-m-d'));
    }

    public function testTodayThrows(): void
    {
        $this->expectException(InvalidValueObjectException::class);

        BirthDate::fromString((new \DateTimeImmutable('today'))->format('Y-m-d'));
    }

    public function testInvalidFormatThrows(): void
    {
        $this->expectException(InvalidValueObjectException::class);

        BirthDate::fromString('15/06/1990');
    }
}
