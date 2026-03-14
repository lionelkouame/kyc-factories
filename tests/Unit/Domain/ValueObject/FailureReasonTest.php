<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\ValueObject;

use App\Domain\KycRequest\Exception\InvalidValueObjectException;
use App\Domain\KycRequest\ValueObject\FailureReason;
use PHPUnit\Framework\TestCase;

final class FailureReasonTest extends TestCase
{
    public function testValidFailureReason(): void
    {
        $reason = new FailureReason('E_UPLOAD_BLUR', 'Image trop floue.');

        self::assertSame('E_UPLOAD_BLUR', $reason->code);
        self::assertSame('Image trop floue.', $reason->message);
    }

    public function testEmptyCodeThrows(): void
    {
        $this->expectException(InvalidValueObjectException::class);

        new FailureReason('', 'Image trop floue.');
    }

    public function testBlankCodeThrows(): void
    {
        $this->expectException(InvalidValueObjectException::class);

        new FailureReason('   ', 'Image trop floue.');
    }

    public function testEmptyMessageThrows(): void
    {
        $this->expectException(InvalidValueObjectException::class);

        new FailureReason('E_UPLOAD_BLUR', '');
    }
}
