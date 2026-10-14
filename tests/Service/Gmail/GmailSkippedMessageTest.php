<?php

declare(strict_types=1);

namespace App\Tests\Service\Gmail;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Mail\GmailSkipReason;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Handler\SyncGmailMessageBatchHandler;
use App\Infrastructure\Messaging\Message\SyncGmailMessageBatchMessage;
use App\Repository\Mail\GmailSkippedMessageRepository;
use App\Service\Mail\GmailApiClient;
use App\Service\Mail\ImportProgress;
use App\Service\Mail\SyncNotifier;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mercure\HubInterface;

/**
 * A Gmail message that is looked at and not stored is written down.
 *
 * #90: an import that sat at 21,964 of 24,199 and said "waiting". The 2,235
 * were messages the batch handler fetches and declines — a draft, a chat, mail
 * its headers tie to no account here — and declining left no trace, so every
 * hourly listing found them missing and fetched them all again. The import
 * ended only when it ran out of attempts, a day later.
 *
 * Through the real handler and a real batch response, because the claims are
 * about what one handled batch leaves behind and what the next one does.
 */
final class GmailSkippedMessageTest extends KernelTestCase
{
    private const string GMAIL_ID = 'gmail-skipped-1';

    private EntityManagerInterface $em;
    private Connection $connection;
    private GmailSkippedMessageRepository $skipped;
    private User $user;
    private Account $account;

    /** How many requests the handler made of Gmail. */
    private int $requests = 0;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->skipped    = $container->get(GmailSkippedMessageRepository::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /** A draft: no Sent label, addressed to somebody else, never delivered anywhere. */
    public function testADeclinedMessageIsRecordedWithWhatTheDecisionWasMadeOn(): void
    {
        $this->handle(['DRAFT'], to: 'Rike <rike@elsewhere.test>');

        self::assertSame(0, $this->storedCount(), 'declined, as before');

        $rows = $this->skipped->findForAccount($this->account);

        self::assertCount(1, $rows);
        self::assertSame(self::GMAIL_ID, $rows[0]->gmailId);
        self::assertSame(GmailSkipReason::Unattributable, $rows[0]->reason);
        self::assertSame(['DRAFT'], $rows[0]->labelIds);
        self::assertSame('Rike <rike@elsewhere.test>', $rows[0]->headers['to'] ?? null);
        self::assertSame('sender@example.test', $rows[0]->headers['from'] ?? null);
        self::assertArrayNotHasKey('subject', $rows[0]->headers, 'why it was declined, not a copy of the mail');
    }

    /** The point of the record: the listing's next batch does not ask Gmail for it at all. */
    public function testADeclinedMessageIsNotFetchedAgainByTheNextListing(): void
    {
        $this->handle(['DRAFT'], to: 'rike@elsewhere.test', onlyMissing: true);

        self::assertSame(1, $this->requests);

        $this->handle(['DRAFT'], to: 'rike@elsewhere.test', onlyMissing: true);

        self::assertSame(1, $this->requests, 'nothing was asked for the second time');
        self::assertSame(1, $this->skipped->countForAccount($this->account));
    }

    /** A label change re-fetches it deliberately; that must not write a second row. */
    public function testDecliningTheSameMessageTwiceIsOneRecord(): void
    {
        $this->handle(['DRAFT'], to: 'rike@elsewhere.test');
        $this->handle(['DRAFT', 'STARRED'], to: 'rike@elsewhere.test');

        self::assertSame(2, $this->requests);
        self::assertSame(1, $this->skipped->countForAccount($this->account));
    }

    /** Gmail lists it and answers 404 for it: not coming, and not to be asked for hourly either. */
    public function testAMessageGmailCannotProduceIsRecordedAsUnfetchable(): void
    {
        $this->handle([], to: '', status: 404);

        $rows = $this->skipped->findForAccount($this->account);

        self::assertCount(1, $rows);
        self::assertSame(GmailSkipReason::Unfetchable, $rows[0]->reason);
        self::assertSame([], $rows[0]->headers);
    }

    /** Declined once is not declined for good: when it can be attributed after all, it is stored and the record goes. */
    public function testAMessageThatBecomesAttributableIsStoredAndComesOffTheRecord(): void
    {
        $this->handle(['INBOX'], to: 'somebody-else@elsewhere.test');

        self::assertSame(1, $this->skipped->countForAccount($this->account));

        $this->handle(['INBOX'], to: 'gmail@example.test');

        self::assertSame(1, $this->storedCount());
        self::assertSame(0, $this->skipped->countForAccount($this->account));
    }

    /**
     * 21,964 of 24,199 with nothing still to come. The total is Google's count
     * of the mailbox, so what was looked at and declined has to count as done.
     */
    public function testTheImportLineCountsADeclinedMessageAsDealtWith(): void
    {
        $this->account->importTotal = 1;
        $this->em->flush();

        $progress = self::getContainer()->get(ImportProgress::class);

        self::assertSame(0, $progress->forUser($this->user)[0]->done);

        $this->handle(['DRAFT'], to: 'rike@elsewhere.test');

        self::assertSame(1, $progress->forUser($this->user)[0]->done);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function storedCount(): int
    {
        return (int) $this->em->createQuery(
            'SELECT COUNT(m.id) FROM ' . Message::class . ' m WHERE m.account = :account',
        )->setParameter('account', $this->account)->getSingleScalarResult();
    }

    /**
     * Run the batch handler for the one message, with Gmail answering this.
     *
     * @param list<string> $labelIds
     */
    private function handle(array $labelIds, string $to, int $status = 200, bool $onlyMissing = false): void
    {
        $payload = json_encode([
            'id'           => self::GMAIL_ID,
            'threadId'     => 'gmail-skipped-thread',
            'labelIds'     => $labelIds,
            'internalDate' => (string) (time() * 1000),
            'payload'      => [
                'headers' => array_values(array_filter([
                    ['name' => 'Message-ID', 'value' => '<gmail-skipped-1@example.test>'],
                    ['name' => 'Subject', 'value' => 'About the invoice'],
                    ['name' => 'From', 'value' => 'sender@example.test'],
                    '' === $to ? null : ['name' => 'To', 'value' => $to],
                ])),
            ],
        ], JSON_THROW_ON_ERROR);

        $boundary = 'batch_plmail_test';

        $body = "--{$boundary}\r\n"
            . "Content-Type: application/http\r\n"
            . "Content-ID: <response-" . self::GMAIL_ID . ">\r\n"
            . "\r\n"
            . (200 === $status ? "HTTP/1.1 200 OK\r\n" : "HTTP/1.1 {$status} Not Found\r\n")
            . "Content-Type: application/json\r\n"
            . "\r\n"
            . (200 === $status ? $payload : '{"error":{"code":' . $status . ',"message":"Requested entity was not found."}}') . "\r\n"
            . "--{$boundary}--";

        $httpClient = new MockHttpClient(function () use ($body, $boundary): MockResponse {
            ++$this->requests;

            return new MockResponse($body, [
                'response_headers' => ['content-type' => 'multipart/mixed; boundary="' . $boundary . '"'],
            ]);
        });

        $container = self::getContainer();
        $pacer     = new \App\Service\Gmail\GmailQuotaPacer(new \Symfony\Component\Clock\MockClock());

        $handler = new SyncGmailMessageBatchHandler(
            $container->get('App\Repository\Mail\MessageRepository'),
            $container->get('App\Repository\Mail\AccountRepository'),
            new GmailApiClient($httpClient, $container->get('App\Service\OAuth\OAuthTokenManager'), $pacer),
            $container->get('App\Service\Gmail\GmailMessageBuilder'),
            $container->get('App\Service\Gmail\GmailAddressFilter'),
            $container->get('App\Service\HarvestContactsService'),
            // The real notifier publishes to Mercure, which the test kernel has
            // no signing key for. What it announces is not what is under test.
            new SyncNotifier($this->createStub(HubInterface::class), new NullLogger()),
            $container->get('messenger.default_bus'),
            $container->get('App\Service\Mail\PostIngestPipeline'),
            $this->em,
            $container->get('App\Jmap\State\StateManager'),
            new NullLogger(),
            $container->get('App\Service\Label\ThreadLabelSynchronizer'),
            $container->get('App\Service\Mail\ThreadStatusUpdater'),
            $container->get('App\Service\Mail\MessageCategorizer'),
            $container->get('App\Service\Imap\MessageThreader'),
            new \App\Service\Mail\SyncOrigin(),
            $pacer,
            $this->skipped,
        );

        $handler(new SyncGmailMessageBatchMessage((int) $this->account->id, [self::GMAIL_ID], null, $onlyMissing));

        $this->em->flush();
    }

    private function seedAccount(): Account
    {
        $account                 = new Account();
        $account->usr            = $this->user;
        $account->name           = 'Gmail fixture';
        $account->email          = 'gmail@example.test';
        $account->username       = uniqid('gmail-', true);
        $account->imapHost       = 'imap.gmail.com';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';

        $account->authType         = AuthType::OAuth2->value;
        $account->oauthProvider    = 'google';
        $account->oauthAccessToken = 'test-access-token';
        $account->oauthTokenExpiry = new DateTimeImmutable('+1 day');
        $account->isActive         = true;

        $this->em->persist($account);
        $this->em->flush();

        $this->user->addAccount($account);
        $this->em->flush();

        return $account;
    }

    private function seedUser(): User
    {
        $user            = new User();
        $user->email     = 'gmail-skipped-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Gmail';
        $user->nameLast  = 'Skipped';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
