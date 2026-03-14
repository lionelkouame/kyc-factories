<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\ValueObject;

use App\Domain\KycRequest\Exception\InvalidValueObjectException;
use App\Domain\KycRequest\ValueObject\DocumentId;
use PHPUnit\Framework\TestCase;

final class DocumentIdTest extends TestCase
{
    public function testValid9Chars(): void
    {
        self::assertSame('AB1234567', DocumentId::fromString('AB1234567')->toString());
    }

    public function testValid12Chars(): void
    {
        self::assertSame('AB1234567890', DocumentId::fromString('AB1234567890')->toString());
    }

    public function testTooShortThrows(): void
    {
        $this->expectException(InvalidValueObjectException::class);

        DocumentId::fromString('AB12345');
    }

    public function testTooLongThrows(): void
    {
        $this->expectException(InvalidValueObjectException::class);

        DocumentId::fromString('AB1234567890123');
    }

    public function testLowercaseThrows(): void
    {
        $this->expectException(InvalidValueObjectException::class);

        DocumentId::fromString('ab123456789');
    }

    public function testSpecialCharThrows(): void
    {
        $this->expectException(InvalidValueObjectException::class);

        DocumentId::fromString('AB-23456789');
    }
}
