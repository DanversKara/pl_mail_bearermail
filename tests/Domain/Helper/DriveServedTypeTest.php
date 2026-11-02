<?php

declare(strict_types=1);

namespace App\Tests\Domain\Helper;

use App\Domain\Helper\DriveServedType;
use PHPUnit\Framework\TestCase;

final class DriveServedTypeTest extends TestCase
{
    public function testAScriptIsServedAsBytes(): void
    {
        self::assertSame('application/octet-stream', DriveServedType::of('text/javascript'));
        self::assertSame('application/octet-stream', DriveServedType::of('application/javascript; charset=utf-8'));
        self::assertSame('application/octet-stream', DriveServedType::of('text/html'));
        self::assertSame('application/octet-stream', DriveServedType::of('image/svg+xml'));
        self::assertSame('application/octet-stream', DriveServedType::of(null));
    }

    public function testAPictureKeepsItsType(): void
    {
        self::assertSame('image/png', DriveServedType::of('IMAGE/PNG'));
        self::assertSame('application/pdf', DriveServedType::of('application/pdf'));
        self::assertTrue(DriveServedType::isSafe('video/mp4'));
    }
}
