<?php

declare(strict_types=1);

namespace App\Service\Insight;

use App\Entity\Insight\MailInsight;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\Insight\MailInsightRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs the enabled extractors over a message and lands their drafts as rows —
 * the one place upsert semantics live.
 *
 * Upsert by (account, extractor-scoped dedupe key), because the interesting
 * mails come in series: shipped, out for delivery, delivered are three mails
 * and one parcel. A later draft OVERWRITES title, payload and happensAt —
 * carriers revise their estimates and the newest statement is the card worth
 * showing — but never clears dismissedAt: the user's "stop showing me this"
 * outranks the carrier's enthusiasm (see MailInsight's class doc).
 *
 * The dedupe key is prefixed with the extractor's key here rather than by
 * each extractor, so two extractors reading the same number cannot collide
 * and no extractor can forget the scoping.
 */
final readonly class InsightHarvester
{
    public function __construct(
        private InsightExtractorRegistry $registry,
        private MailInsightRepository $insights,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /** @return int how many insights were written or refreshed */
    public function harvest(Message $message): int
    {
        $account = $message->account;
        $user = $account->usr;

        if (!$user instanceof User) {
            return 0;
        }

        $written = 0;

        foreach ($this->registry->enabledFor($user) as $extractor) {
            // Per extractor, so one parser choking on one strange mail costs
            // its own facts and nobody else's.
            try {
                if (false === $extractor->supports($message)) {
                    continue;
                }

                $keys = [];
                foreach ($extractor->extract($message) as $draft) {
                    $keys[] = $this->land($message, $extractor::key(), $draft);
                    $written++;
                }

                if (\in_array($extractor::key(), self::ONE_PER_MESSAGE, true)) {
                    // Counted as a change so the caller flushes the removal.
                    $written += $this->dropStale($message, $extractor::key(), $keys);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Insight extractor failed on a message', [
                    'extractor' => $extractor::key(),
                    'message'   => $message->id,
                    'error'     => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }

        return $written;
    }

    /**
     * BearerMail: extractors whose card belongs to ONE mail rather than to a
     * series. When such a mail is read again (app:backfill insights) and the
     * extractor now finds something different — a code it used to misread —
     * the old card is a wrong answer still on screen, so it goes. Plans
     * found in a mail are the same: one mail, one card.
     *
     * Not parcels, flights or invoices: their cards collect a series of mails,
     * and re-reading one mail of the series must not remove the card.
     */
    private const array ONE_PER_MESSAGE = ['otp', 'meeting'];

    /**
     * @param list<string> $keep dedupe keys just written for this message
     *
     * @return int how many stale cards were removed
     */
    private function dropStale(Message $message, string $extractorKey, array $keep): int
    {
        $removed = 0;
        foreach ($this->insights->findBy(['message' => $message, 'extractor' => $extractorKey]) as $insight) {
            if (false === \in_array($insight->dedupeKey, $keep, true)) {
                $this->em->remove($insight);
                $removed++;
            }
        }

        return $removed;
    }

    /** @return string the scoped dedupe key the draft landed under */
    private function land(Message $message, string $extractorKey, InsightDraft $draft): string
    {
        $dedupeKey = mb_substr($extractorKey . ':' . $draft->dedupeKey, 0, 160);

        $insight = $this->insights->findOneByDedupe($message->account, $dedupeKey);

        if (null === $insight) {
            $insight = new MailInsight();
            $insight->account = $message->account;
            $insight->extractor = $extractorKey;
            $insight->dedupeKey = $dedupeKey;

            $this->em->persist($insight);
        }

        $insight->kind = $draft->kind;
        $insight->title = mb_substr($draft->title, 0, 255);
        $insight->payload = $draft->payload;
        $insight->happensAt = $draft->happensAt;
        // The newest mail in the series owns the link: "open the mail" should
        // land on "delivered", not on "shipped".
        $insight->message = $message;
        $insight->thread = $message->thread;

        return $dedupeKey;
    }
}
