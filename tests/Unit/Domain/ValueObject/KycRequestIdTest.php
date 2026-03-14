<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\ValueObject;

use App\Domain\KycRequest\Exception\InvalidValueObjectException;
use App\Domain\KycRequest\ValueObject\KycRequestId;
use PHPUnit\Framework\TestCase;

final class KycRequestIdTest extends TestCase
{
    public function testGenerateProducesValidUuid(): void
    {
        $id = KycRequestId::generate();

        self::assertNotEmpty($id->toString());
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $id->toString()
        );
    }

    public function testFromValidStringRoundtrips(): void
    {
        $uuid = '01930b7a-1234-7abc-8def-123456789abc';
        $id = KycRequestId::fromString($uuid);

        self::assertSame($uuid, $id->toString());
    }

    public function testFromInvalidStringThrowsException(): void
    {
        $this->expectException(InvalidValueObjectException::class);

        KycRequestId::fromString('not-a-uuid');
    }

    public function testEqualityReturnsTrueForSameUuid(): void
    {
        $uuid = '01930b7a-1234-7abc-8def-123456789abc';

        self::assertTrue(
            KycRequestId::fromString($uuid)->equals(KycRequestId::fromString($uuid))
        );
    }

    public function testEqualityReturnsFalseForDifferentUuids(): void
    {
        self::assertFalse(KycRequestId::generate()->equals(KycRequestId::generate()));
    }
}
