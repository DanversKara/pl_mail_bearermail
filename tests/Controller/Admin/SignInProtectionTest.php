<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User\User;
use App\Repository\User\UserRepository;
use App\Service\Security\SignInProtection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Admin → Security → Sign-in protection: the Admin network lock and
 * two-factor sign-in required. Skips itself without the seeded admin, as the
 * admin tests next door do.
 */
final class SignInProtectionTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private KernelBrowser $client;
    private string $file;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container    = static::getContainer();
        $this->file   = (string) $container->getParameter('app.sign_in_protection_file');
        @unlink($this->file);

        $admin = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);
        if (false === $admin instanceof User) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }
        if (true === $admin->isTotpAuthenticationEnabled()) {
            self::markTestSkipped('the seeded admin has two-factor on');
        }

        $this->client->loginUser($admin);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    public function testTheCardRendersWithTheCallersAddress(): void
    {
        $this->client->request('GET', '/admin/security/sign-in');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#admin-sign-in textarea[name="adminNetworks"]');
        self::assertSelectorTextContains('turbo-frame#admin-sign-in', '127.0.0.1');
    }

    public function testALockThatLeavesOutTheSaverIsRefused(): void
    {
        $this->save(['adminLock' => '1', 'adminNetworks' => '203.0.113.7']);

        self::assertResponseRedirects();
        self::assertStringContainsString('would_lock_out', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertFalse(new SignInProtection($this->file)->settings()['adminLock']);
    }

    public function testAdminIsRefusedFromOutsideTheListAndOpenFromInside(): void
    {
        $this->save(['adminLock' => '1', 'adminNetworks' => "127.0.0.1\n192.168.1.0/24"]);
        self::assertTrue(new SignInProtection($this->file)->settings()['adminLock']);

        $this->client->request('GET', '/admin', server: ['REMOTE_ADDR' => '198.51.100.4']);
        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('198.51.100.4', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/admin', server: ['REMOTE_ADDR' => '192.168.1.40']);
        self::assertResponseIsSuccessful();
    }

    public function testRequiredTwoFactorSendsTheUserToSetUpAndKeepsSettingsOpen(): void
    {
        $this->save(['twoFactor' => 'admins', 'adminNetworks' => '']);

        $this->client->request('GET', '/');
        self::assertResponseRedirects();
        self::assertStringContainsString('section=security', (string) $this->client->getResponse()->headers->get('Location'));

        $this->client->request('GET', '/settings?section=security');
        self::assertResponseIsSuccessful();
    }

    /** @param array<string, string> $fields */
    private function save(array $fields): void
    {
        $this->client->request('GET', '/admin/security/sign-in');
        $token = (string) $this->client->getCrawler()->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/admin/security/sign-in', ['_token' => $token, 'twoFactor' => 'off'] + $fields);
    }
}
