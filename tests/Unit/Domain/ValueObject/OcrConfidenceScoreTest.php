<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\ValueObject;

use App\Domain\KycRequest\Exception\InvalidValueObjectException;
use App\Domain\KycRequest\ValueObject\OcrConfidenceScore;
use PHPUnit\Framework\TestCase;

final class OcrConfidenceScoreTest extends TestCase
{
    public function testValidScoreAt0(): void
    {
        self::assertSame(0.0, OcrConfidenceScore::fromFloat(0.0)->toFloat());
    }

    public function testValidScoreAt100(): void
    {
        self::assertSame(100.0, OcrConfidenceScore::fromFloat(100.0)->toFloat());
    }

    public function testAboveThreshold(): void
    {
        self::assertTrue(OcrConfidenceScore::fromFloat(75.0)->isAboveThreshold(60.0));
    }

    public function testBelowThreshold(): void
    {
        self::assertFalse(OcrConfidenceScore::fromFloat(45.0)->isAboveThreshold(60.0));
    }

    public function testNegativeThrows(): void
    {
        $this->expectException(InvalidValueObjectException::class);

        OcrConfidenceScore::fromFloat(-1.0);
    }

    public function testAbove100Throws(): void
    {
        $this->expectException(InvalidValueObjectException::class);

        OcrConfidenceScore::fromFloat(100.1);
    }
}
