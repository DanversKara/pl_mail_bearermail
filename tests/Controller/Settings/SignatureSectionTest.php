<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Enum\Account\EmailAliasSource;
use App\Domain\Enum\Account\EmailAliasStatus;
use App\Entity\Mail\Account;
use App\Entity\Mail\EmailAlias;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The Signatures settings section.
 *
 * Split out of ComposeSignatureAndOptionsTest along with the panel itself,
 * which now has its own section and its own controller.
 *
 * WHAT THE OLD SUITE MISSED, AND WHY
 * ──────────────────────────────────
 * Users reported that an inheriting alias's editor looked live until the
 * inherit box was toggled, and the suite was green throughout. It was green
 * because it only ever asserted `contenteditable="false"` — which the markup
 * DID set correctly on first render. The greying was applied by a separate
 * mechanism (a `data-signature-disabled` attribute that matched no CSS rule,
 * plus an `opacity-50` class added only by the checkbox's change handler), and
 * nothing asserted the two agreed. An assertion on the attribute could not
 * fail for a bug that lived in the appearance.
 *
 * The gap is closed by not having a disabled state to get wrong: an inheriting
 * address renders NO editor, and testAnInheritingAliasRendersNoEditor asserts
 * an ABSENCE. Absence is a property of the rendered document, so no second
 * mechanism can silently disagree with it.
 */
final class SignatureSectionTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    /** A one-pixel PNG, the same one SignatureImporterTest uses. */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private EntityManagerInterface $em;
    private Connection $connection;

    /** @var list<string> temp files the upload tests made, for tearDown */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->tmpFiles = [];

        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    // ── the section ──────────────────────────────────────────────────────────

    public function testTheSectionRendersAndIsReachableFromTheNav(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);

        $this->account($user, 'nav@joder.dev');
        $this->em->flush();

        $crawler = $client->request('GET', '/settings?section=accounts');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(
            0,
            $crawler->filter('nav a[href*="section=signature"]')->count(),
            'the settings nav links to the signature section',
        );

        $crawler = $client->request('GET', '/settings?section=signature');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('#settings-signature')->count());
        self::assertSelectorTextContains('h2', 'Signatures');

        // The section survives SettingsController::SECTIONS — an unknown one
        // falls back to accounts, which would render no panel at all.
        self::assertGreaterThan(
            0,
            $crawler->filter('nav a[href*="section=signature"][aria-current="page"]')->count(),
            'the nav marks the signature section as the current page',
        );
    }

    // ── "gone, not greyed" ───────────────────────────────────────────────────

    /**
     * The bug the overhaul was for: an alias with no signature of its own gets
     * ONE LINE, and the editor is not in the document.
     *
     * Asserted as an absence deliberately — see the class docblock.
     */
    public function testAnInheritingAliasRendersNoEditor(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'inherits@joder.dev');
        $alias   = $this->alias($account, 'quiet@joder.dev');

        $account->setSetting(Account::SETTING_SIGNATURE, '<p>Account wide</p>');
        $this->em->flush();

        $crawler = $client->request('GET', '/settings?section=signature');

        self::assertResponseIsSuccessful();

        $row = $crawler->filter(sprintf('[data-signature-alias="%d"]', $alias->id));

        self::assertSame(1, $row->count(), 'the alias has a row');
        self::assertSame('inherits', $row->attr('data-signature-state'));
        self::assertSame(0, $row->filter('[contenteditable]')->count(), 'and no editor of any kind in it');
        self::assertSame(0, $row->filter('[data-signature-disabled]')->count());
        self::assertSame(0, $row->filter('form[data-controller="compose--compose-toolbar"]')->count());

        // Exactly one editor on the page: the account's.
        self::assertSame(
            1,
            $crawler->filter('#settings-signature [contenteditable="true"]')->count(),
            'one account editor and nothing else',
        );

        // And nothing anywhere claims to be a disabled editor.
        self::assertSame(0, $crawler->filter('[contenteditable="false"]')->count());
    }

    /**
     * Overriding, then reverting, and the row shape follows the state both ways
     * without the user having to toggle anything twice.
     */
    public function testOverridingThenRevertingRestoresInheritance(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'roundtrip@joder.dev');
        $alias   = $this->alias($account, 'own@joder.dev');

        $account->setSetting(Account::SETTING_SIGNATURE, '<p>Account wide</p>');
        $this->em->flush();

        $key = Account::signatureAliasSetting((int) $alias->id);
        $url = '/account/' . $account->id . '/signature/' . $alias->id;

        self::assertNull($this->reload($account)->getSetting($key));

        // "Use a different one" — seeded from the account signature, so the
        // mail this address sends does not change the moment you press it.
        $token = $this->aliasToken($client, $alias->id);

        $client->request('POST', $url, ['_token' => $token, 'override' => '1']);
        self::assertResponseRedirects('/settings?section=signature');

        $stored = $this->reload($account)->getSetting($key);

        self::assertIsString($stored);
        self::assertStringContainsString('Account wide', $stored);

        // ...and now the row IS an editor, on first render, no toggling.
        $crawler = $client->request('GET', '/settings?section=signature');
        $row     = $crawler->filter(sprintf('[data-signature-alias="%d"]', $alias->id));

        self::assertSame('overrides', $row->attr('data-signature-state'));
        self::assertSame(1, $row->filter('[contenteditable="true"]')->count());
        self::assertSame(0, $row->filter('[contenteditable="false"]')->count());

        // "Use the account signature" — the key goes away again.
        $client->request('POST', $url, ['_token' => $token, 'inherit' => '1']);
        self::assertResponseRedirects('/settings?section=signature');
        self::assertNull($this->reload($account)->getSetting($key));

        $crawler = $client->request('GET', '/settings?section=signature');
        $row     = $crawler->filter(sprintf('[data-signature-alias="%d"]', $alias->id));

        self::assertSame('inherits', $row->attr('data-signature-state'));
        self::assertSame(0, $row->filter('[contenteditable]')->count());
    }

    /**
     * Pressing "use a different one" on an address that already has one must
     * not overwrite it with the account's copy — a stale panel can post it.
     */
    public function testOverridingAnAddressThatAlreadyOverridesKeepsItsSignature(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'stale@joder.dev');
        $alias   = $this->alias($account, 'kept@joder.dev');

        $account->setSetting(Account::SETTING_SIGNATURE, '<p>Account wide</p>');
        $account->setSetting(Account::signatureAliasSetting((int) $alias->id), '<p>Mine</p>');
        $this->em->flush();

        $url = '/account/' . $account->id . '/signature/' . $alias->id;

        $client->request('POST', $url, [
            '_token'   => $this->aliasToken($client, $alias->id),
            'override' => '1',
        ]);

        self::assertResponseRedirects('/settings?section=signature');
        self::assertSame(
            '<p>Mine</p>',
            $this->reload($account)->getSetting(Account::signatureAliasSetting((int) $alias->id)),
        );
    }

    // ── the disclosure ───────────────────────────────────────────────────────

    /**
     * Closed when nothing is overridden, open when something is — the whole
     * point of the compact default is that it never hides configuration you
     * already made.
     */
    public function testTheDisclosureStartsOpenOnlyWhenAnAddressOverrides(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'disclosure@joder.dev');
        $alias   = $this->alias($account, 'one@joder.dev');

        $this->alias($account, 'two@joder.dev');
        $this->alias($account, 'three@joder.dev');
        $this->em->flush();

        $crawler = $client->request('GET', '/settings?section=signature');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('#settings-signature details')->count());
        self::assertNull(
            $crawler->filter('#settings-signature details')->attr('open'),
            'no overrides, so the per-address list starts folded away',
        );

        $account->setSetting(Account::signatureAliasSetting((int) $alias->id), '<p>Mine</p>');
        $this->em->flush();

        $crawler = $client->request('GET', '/settings?section=signature');

        self::assertNotNull(
            $crawler->filter('#settings-signature details')->attr('open'),
            'one override, so it starts open',
        );
    }

    // ── the three states, through the UI ─────────────────────────────────────

    public function testSavingAnAccountSignatureSanitisesIt(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'settings@joder.dev');

        $this->em->flush();

        $url = '/account/' . $account->id . '/signature';

        $client->request('POST', $url, [
            '_token'    => $this->csrf($client, $url),
            'signature' => '<p>Ada</p><script>alert(1)</script>',
        ]);

        // A plain POST, so the controller's redirect fallback answers rather
        // than the Turbo Stream — the panel works without JavaScript too.
        self::assertResponseRedirects('/settings?section=signature');

        $stored = (string) $this->reload($account)->getSetting(Account::SETTING_SIGNATURE);

        self::assertStringNotContainsString('script', $stored);
        self::assertStringContainsString('Ada', $stored);
    }

    /**
     * The three-state distinction, driven the way the new panel drives it:
     * absent, then a stored empty string, then absent again. The middle state
     * is reached by clearing a real editor and saving, which is the only path
     * the UI offers — and it must not be mistaken for inheriting.
     */
    public function testTheThreeStatesSurviveARoundTripThroughTheUi(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'aliases@joder.dev');
        $alias   = $this->alias($account, 'other@joder.dev');

        $account->setSetting(Account::SETTING_SIGNATURE, '<p>Account wide</p>');
        $this->em->flush();

        $key   = Account::signatureAliasSetting((int) $alias->id);
        $url   = '/account/' . $account->id . '/signature/' . $alias->id;
        $token = $this->aliasToken($client, $alias->id);

        // 1. absent — this address follows the account.
        self::assertNull($this->reload($account)->getSetting($key));

        // 2. present, HTML of its own.
        $client->request('POST', $url, ['_token' => $token, 'override' => '1']);
        $client->request('POST', $url, ['_token' => $token, 'signature' => '<p>Mine</p>']);

        self::assertResponseRedirects('/settings?section=signature');
        self::assertSame('<p>Mine</p>', $this->reload($account)->getSetting($key));

        // 3. present but empty — deliberately signs with nothing. The panel
        //    says so in words rather than looking like the inheriting row.
        $client->request('POST', $url, ['_token' => $token, 'signature' => '']);

        self::assertResponseRedirects('/settings?section=signature');
        self::assertSame('', $this->reload($account)->getSetting($key));

        $crawler = $client->request('GET', '/settings?section=signature');
        $row     = $crawler->filter(sprintf('[data-signature-alias="%d"]', $alias->id));

        self::assertSame('overrides', $row->attr('data-signature-state'), 'empty is not inherit');
        self::assertSame(1, $row->filter('[contenteditable="true"]')->count(), 'and still has its editor');
        self::assertStringContainsString('signs with nothing', $row->text());

        // ...and back to absent, which is not the same value as ''.
        $client->request('POST', $url, ['_token' => $token, 'inherit' => '1']);

        self::assertResponseRedirects('/settings?section=signature');
        self::assertNull($this->reload($account)->getSetting($key));
    }

    public function testSavingASignatureWithoutACsrfTokenIsRefused(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'nocsrf@joder.dev');

        $this->em->flush();

        $client->request('POST', '/account/' . $account->id . '/signature', [
            'signature' => '<p>Nope</p>',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnAliasOnSomebodyElsesAccountIsRefused(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'mine@joder.dev');
        $alias   = $this->alias($account, 'yours@joder.dev');

        $this->em->flush();

        $other = $this->account($user, 'other-account@joder.dev');

        $client->request('POST', '/account/' . $other->id . '/signature/' . $alias->id, [
            '_token'  => $this->aliasToken($client, $alias->id),
            'inherit' => '1',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    // ── the image upload ─────────────────────────────────────────────────────

    /**
     * The fourth way to have a signature: a file, not a drawing, not text,
     * not HTML. The picture goes BELOW what is already there — the words
     * stay — and what is stored is the same data: image the importer would
     * have made of a pasted <img>.
     */
    public function testUploadingAnImageAppendsItBelowTheExistingSignature(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'image@joder.dev');

        $account->setSetting(Account::SETTING_SIGNATURE, '<p>Best</p>');
        $this->em->flush();

        $url  = '/account/' . $account->id . '/signature/image';
        $file = $this->pngFile();

        $client->request('POST', $url, ['_token' => $this->csrf($client, $url)], ['image' => $file]);

        self::assertResponseRedirects('/settings?section=signature');

        $stored = (string) $this->reload($account)->getSetting(Account::SETTING_SIGNATURE);

        self::assertStringContainsString('Best', $stored, 'the text stays');
        self::assertStringContainsString('src="data:image/png;base64,' . self::PNG . '"', $stored);
        self::assertGreaterThan(
            strpos($stored, 'Best'),
            strpos($stored, 'data:image/png'),
            'the image sits below the text, not above it',
        );

        $this->unlink($file);
    }

    /**
     * Nothing to sit below on an empty signature: just the picture, with no
     * stray break in front of it.
     */
    public function testUploadingAnImageToAnEmptySignatureStoresJustTheImage(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'empty-image@joder.dev');

        $this->em->flush();

        $url  = '/account/' . $account->id . '/signature/image';
        $file = $this->pngFile();

        $client->request('POST', $url, ['_token' => $this->csrf($client, $url)], ['image' => $file]);

        self::assertResponseRedirects('/settings?section=signature');

        $stored = (string) $this->reload($account)->getSetting(Account::SETTING_SIGNATURE);

        self::assertStringContainsString('src="data:image/png;base64,' . self::PNG . '"', $stored);
        self::assertStringNotContainsString('<br>', $stored);

        $this->unlink($file);
    }

    public function testUploadingANonImageIsRefused(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'not-image@joder.dev');

        $this->em->flush();

        $url  = '/account/' . $account->id . '/signature/image';
        $path = tempnam(sys_get_temp_dir(), 'sig');
        file_put_contents($path, 'this is not an image');

        $client->request('POST', $url, ['_token' => $this->csrf($client, $url)], [
            'image' => new UploadedFile($path, 'notes.txt', 'text/plain', null, true),
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertNull($this->reload($account)->getSetting(Account::SETTING_SIGNATURE));

        unlink($path);
    }

    /**
     * The cap is the importer's own: an image it would not embed is not
     * stored as a remote URL either.
     */
    public function testUploadingAnOversizeImageIsRefused(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'big-image@joder.dev');

        $this->em->flush();

        $url  = '/account/' . $account->id . '/signature/image';
        $file = $this->pngFile(256 * 1024 + 1);

        $client->request('POST', $url, ['_token' => $this->csrf($client, $url)], ['image' => $file]);

        self::assertResponseStatusCodeSame(400);
        self::assertNull($this->reload($account)->getSetting(Account::SETTING_SIGNATURE));

        $this->unlink($file);
    }

    public function testUploadingAnImageWithoutACsrfTokenIsRefused(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'nocsrf-image@joder.dev');

        $this->em->flush();

        $client->request('POST', '/account/' . $account->id . '/signature/image', [], [
            'image' => $this->pngFile(),
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testUploadingAnImageToAnUnknownAccountIsRefused(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);
        $this->account($user, 'known@joder.dev');
        $this->em->flush();

        $client->request('POST', '/account/999999/signature/image', [
            '_token' => 'not-the-token',
        ], [
            'image' => $this->pngFile(),
        ]);

        // No such account, so there is nothing to own — and the token is
        // wrong besides. Either way it is not a 200 with a stored image.
        self::assertResponseStatusCodeSame(404);
    }

    // ── the move ─────────────────────────────────────────────────────────────

    /**
     * The handwritten signature used to live under Profile. It lives under
     * Signatures now: the pad is on the signature page, and the profile
     * page no longer carries it.
     */
    public function testTheHandwritingCardLivesUnderSignaturesNow(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);
        $this->account($user, 'moved@joder.dev');
        $this->em->flush();

        $crawler = $client->request('GET', '/settings?section=signature');

        self::assertResponseIsSuccessful();
        // Not assertSelectorTextContains('h2', …): the first h2 on the page
        // is the Signatures card's own, and the handwriting card is the
        // second one — the heading is asserted as page text instead.
        self::assertStringContainsString('Handwritten signature', $crawler->text());
        self::assertSame(
            1,
            $crawler->filter('canvas[data-settings--signature-pad-target="ink"]')->count(),
            'the draw pad is on the signature page',
        );

        $crawler = $client->request('GET', '/settings?section=profile');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Handwritten signature', $crawler->text());
        self::assertSame(
            0,
            $crawler->filter('canvas[data-settings--signature-pad-target="ink"]')->count(),
            'and no longer on the profile page',
        );
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * A PNG upload, padded to a size when the test wants one over the cap.
     *
     * Padding with null bytes leaves the header intact, so the mime guesser
     * still reads image/png — the size check is the only thing being tested.
     */
    private function pngFile(int $padTo = 0): UploadedFile
    {
        $bytes = (string) base64_decode(self::PNG, true);

        if ($padTo > strlen($bytes)) {
            $bytes .= str_repeat("\0", $padTo - strlen($bytes));
        }

        $path = tempnam(sys_get_temp_dir(), 'sig') . '.png';

        file_put_contents($path, $bytes);

        $this->tmpFiles[] = $path;

        return new UploadedFile($path, 'logo.png', 'image/png', null, true);
    }

    private function unlink(UploadedFile $file): void
    {
        $path = $file->getPathname();

        if (is_file($path)) {
            unlink($path);
        }

        $this->tmpFiles = array_values(array_filter(
            $this->tmpFiles,
            static fn (string $kept): bool => $kept !== $path,
        ));
    }

    /**
     * The token off the rendered panel, not one minted out of band.
     *
     * A token asked of the manager directly has no session behind it in a
     * functional test, and reading the real one also proves the panel actually
     * draws the control being posted to.
     */
    private function csrf(KernelBrowser $client, string $formAction): string
    {
        $crawler = $client->request('GET', '/settings?section=signature');

        self::assertResponseIsSuccessful();

        $form = $crawler->filter(sprintf('form[action="%s"]', $formAction));

        self::assertGreaterThan(0, $form->count(), sprintf('the panel renders a form posting to %s', $formAction));

        return (string) $form->filter('input[name="_token"]')->attr('value');
    }

    /**
     * The alias row's token, whichever shape the row currently has.
     *
     * Override, revert and save all post to the same route under the same CSRF
     * id — see SignatureController — so one lookup answers for every state the
     * row can be in, including the inheriting one that has no editor.
     */
    // ── HTML source and its rendering ────────────────────────────────────────

    /**
     * The "Rendered" view asks the server what the source turns into, and the
     * answer is what a save would keep — nothing is stored by asking.
     */
    public function testThePreviewReturnsWhatASaveWouldKeepAndStoresNothing(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'source@joder.dev');

        $crawler = $client->request('GET', '/settings?section=signature');
        $form    = $crawler->filter(sprintf('form[action="/account/%d/signature"]', $account->id));

        self::assertSame(1, $form->filter('textarea[data-settings--signature-source-target="source"]')->count());
        self::assertSame(
            sprintf('/account/%d/signature/preview', $account->id),
            $form->attr('data-settings--signature-source-url-value'),
        );

        $client->request('POST', (string) $form->attr('data-settings--signature-source-url-value'), [
            '_token'    => (string) $form->attr('data-settings--signature-source-token-value'),
            'signature' => '<style>.s{color:red}</style><p class="s">Ada</p><script>alert(1)</script>',
        ]);

        self::assertResponseIsSuccessful();

        $preview = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('<p>Ada</p>', $preview['html']);
        self::assertSame(['script', 'style'], $preview['removedElements']);
        self::assertSame(['class'], $preview['removedAttributes']);
        self::assertNull($this->reload($account)->getSetting(Account::SETTING_SIGNATURE));
    }

    public function testThePreviewRefusesARequestWithoutItsToken(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'source@joder.dev');

        $client->request('POST', sprintf('/account/%d/signature/preview', $account->id), [
            '_token'    => 'not-the-token',
            'signature' => '<p>Ada</p>',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    private function aliasToken(KernelBrowser $client, int $aliasId): string
    {
        $crawler = $client->request('GET', '/settings?section=signature');

        self::assertResponseIsSuccessful();

        $row = $crawler->filter(sprintf('[data-signature-alias="%d"]', $aliasId));

        self::assertSame(1, $row->count(), 'the panel draws a row for this alias');

        return (string) $row->filter('input[name="_token"]')->first()->attr('value');
    }

    private function reload(Account $account): Account
    {
        $this->em->clear();

        return $this->em->find(Account::class, $account->id);
    }

    private function boot(KernelBrowser $client): User
    {
        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $user = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $client->loginUser($user);
        $client->disableReboot();

        $this->connection->beginTransaction();

        $this->em->createQuery(
            'UPDATE ' . Account::class . ' a SET a.isActive = false WHERE a.usr = :usr',
        )->setParameter('usr', $user)->execute();

        $this->em->clear();

        return $this->em->find(User::class, $user->id);
    }

    private function account(User $user, string $email): Account
    {
        $account = new Account();

        $account->usr       = $user;
        $account->name      = $email;
        $account->username  = $email;
        $account->email     = $email;
        $account->authType  = 'password';
        $account->isActive  = true;
        $account->isPrimary = true;
        $account->sortOrder = 0;
        $account->imapHost  = 'imap.example.test';

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    private function alias(Account $account, string $address): EmailAlias
    {
        $alias = new EmailAlias($account, $address, EmailAliasSource::Manual, EmailAliasStatus::Active);

        $account->addAlias($alias);
        $this->em->persist($alias);
        $this->em->flush();

        return $alias;
    }
}
