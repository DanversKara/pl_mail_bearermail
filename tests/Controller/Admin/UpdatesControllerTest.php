<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Domain\Enum\System\UpdateChannel;
use App\Entity\User\User;
use App\Repository\System\UpdateCheckRepository;
use App\Repository\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Admin → Updates: an administrator's page, and the choice on it sticks.
 *
 * Off is the channel exercised here because it is the one that asks nothing of
 * the network: the checks against a registry are UpdateCheckerTest's, with the
 * registry answered by hand.
 */
final class UpdatesControllerTest extends WebTestCase
{
    protected function tearDown(): void
    {
        // The channel is installation-wide and has no fixture: take the row away
        // so the next suite's installation follows its build, as a fresh one does.
        $container = static::getContainer();
        $check     = $container->get(UpdateCheckRepository::class)->current();

        if (null !== $check) {
            $container->get(EntityManagerInterface::class)->remove($check);
            $container->get(EntityManagerInterface::class)->flush();
        }

        parent::tearDown();
    }

    public function testOnlyAnAdministratorReachesIt(): void
    {
        $client = $this->signedIn('e2e@plmail.test');

        $client->request('GET', '/admin/updates');

        self::assertResponseStatusCodeSame(403);
    }

    public function testChoosingOffIsKeptAndChecksNothing(): void
    {
        $client = $this->signedIn('e2e-admin@plmail.test');

        $crawler = $client->request('GET', '/admin/updates');

        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('turbo-frame#admin-updates input[name="channel"]'), 'one radio per channel');

        $client->request('POST', '/admin/updates', [
            '_token'  => (string) $crawler->filter('turbo-frame#admin-updates input[name="_token"]')->first()->attr('value'),
            'channel' => UpdateChannel::Off->value,
        ]);

        self::assertResponseIsSuccessful();

        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $check = static::getContainer()->get(UpdateCheckRepository::class)->current();

        self::assertSame(UpdateChannel::Off, $check?->channel);
        self::assertNull($check->status, 'Off records no answer, because it asked nothing');
        self::assertNull($check->error);
    }

    /**
     * The auto-install switch sticks both ways, and saving it records nothing
     * on the installer's behalf: only bin/auto-update asking sets that.
     *
     * Stored, not rendered: a test build has no version stamped in, so it
     * follows the Off channel, where the switch is not shown.
     */
    public function testAutoInstallIsKeptAndAsksForTheServerScript(): void
    {
        $client = $this->signedIn('e2e-admin@plmail.test');

        $crawler = $client->request('GET', '/admin/updates');
        $token   = (string) $crawler->filter('turbo-frame#admin-updates input[name="_token"]')->first()->attr('value');

        $client->request('POST', '/admin/updates', ['_token' => $token, 'do' => 'install', 'install' => 'auto']);

        self::assertResponseIsSuccessful();

        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $check = static::getContainer()->get(UpdateCheckRepository::class)->current();

        self::assertTrue($check?->autoInstall);
        self::assertNull($check->installerSeenAt);

        $client->request('POST', '/admin/updates', ['_token' => $token, 'do' => 'install', 'install' => 'notify']);

        static::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertFalse(static::getContainer()->get(UpdateCheckRepository::class)->current()?->autoInstall);
    }

    private function signedIn(string $email): KernelBrowser
    {
        $client = static::createClient();
        $user   = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);

        if (false === $user instanceof User) {
            self::markTestSkipped('run `app:test:seed-user` first');
        }

        $client->loginUser($user);

        return $client;
    }
}
