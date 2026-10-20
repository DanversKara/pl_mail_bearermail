<?php

declare(strict_types=1);

namespace App\Tests\Service\Security;

use App\Service\Security\SignInProtection;
use PHPUnit\Framework\TestCase;

final class SignInProtectionTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/sip-' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testDefaultsLeaveEverythingOpen(): void
    {
        $p = new SignInProtection($this->file);

        self::assertSame(SignInProtection::DEFAULTS, $p->settings());
        self::assertTrue($p->adminAllowedFrom('198.51.100.4'));
    }

    public function testParsesAddressesAndRangesAndRefusesJunk(): void
    {
        self::assertSame(['203.0.113.7', '192.168.1.0/24', '2001:db8::/32'], SignInProtection::parseNetworks("203.0.113.7\n192.168.1.0/24, 2001:db8::/32 203.0.113.7"));
        self::assertSame([], SignInProtection::parseNetworks('  '));
        self::assertNull(SignInProtection::parseNetworks('203.0.113.7 home'));
        self::assertNull(SignInProtection::parseNetworks('10.0.0.0/33'));
    }

    public function testTheLockAdmitsOnlyListedAddressesAndSurvivesARestart(): void
    {
        new SignInProtection($this->file)->save(['adminLock' => true, 'adminNetworks' => ['192.168.1.0/24', '203.0.113.7']]);

        $p = new SignInProtection($this->file);
        self::assertTrue($p->adminAllowedFrom('192.168.1.40'));
        self::assertTrue($p->adminAllowedFrom('203.0.113.7'));
        self::assertFalse($p->adminAllowedFrom('198.51.100.4'));
        self::assertFalse($p->adminAllowedFrom(null));
    }

    public function testAdminNetworkLockOffIsTheWayBackIn(): void
    {
        new SignInProtection($this->file)->save(['adminLock' => true, 'adminNetworks' => ['203.0.113.7']]);

        self::assertTrue(new SignInProtection($this->file, 'off')->adminAllowedFrom('198.51.100.4'));
    }

    public function testAnUnknownTwoFactorChoiceIsOff(): void
    {
        $p = new SignInProtection($this->file);
        $p->save(['twoFactor' => 'sometimes']);

        self::assertSame('off', $p->settings()['twoFactor']);
    }
}
