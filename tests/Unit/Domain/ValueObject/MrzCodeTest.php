<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\ValueObject;

use App\Domain\KycRequest\Exception\InvalidValueObjectException;
use App\Domain\KycRequest\ValueObject\MrzCode;
use PHPUnit\Framework\TestCase;

final class MrzCodeTest extends TestCase
{
    public function testValidTd1Format30Chars(): void
    {
        $mrz = str_repeat('A', 30)."\n".str_repeat('B', 30);

        self::assertSame($mrz, MrzCode::fromString($mrz)->toString());
    }

    public function testValidTd3Format44Chars(): void
    {
        $mrz = str_repeat('A', 44)."\n".str_repeat('B', 44);

        self::assertSame($mrz, MrzCode::fromString($mrz)->toString());
    }

    public function testSingleLineThrows(): void
    {
        $this->expectException(InvalidValueObjectException::class);

        MrzCode::fromString(str_repeat('A', 30));
    }

    public function testInvalidLineLengthThrows(): void
    {
        $this->expectException(InvalidValueObjectException::class);

        MrzCode::fromString(str_repeat('A', 20)."\n".str_repeat('B', 20));
    }

    public function testLowercaseCharsThrow(): void
    {
        $this->expectException(InvalidValueObjectException::class);

        MrzCode::fromString(str_repeat('a', 30)."\n".str_repeat('b', 30));
    }

    public function testEqualityOnSameValue(): void
    {
        $mrz = str_repeat('A', 30)."\n".str_repeat('B', 30);

        self::assertTrue(MrzCode::fromString($mrz)->equals(MrzCode::fromString($mrz)));
    }
}
