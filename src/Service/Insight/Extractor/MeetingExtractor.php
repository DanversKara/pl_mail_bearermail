<?php

declare(strict_types=1);

namespace App\Service\Insight\Extractor;

use App\Domain\Enum\Insight\InsightKind;
use App\Domain\Helper\ReadableBody;
use App\Entity\Mail\Message;
use App\Service\Insight\InsightDraft;
use App\Service\Insight\InsightExtractorInterface;
use App\Service\User\UserTimezoneResolver;
use DateTimeImmutable;
use DateTimeZone;

/**
 * BearerMail: "meet me at 218 Main St on Friday at 10am" — a plan written as
 * plain words, with no invite attached.
 *
 * plMail already turns real invites (.ics) and booking confirmations
 * (schema.org JSON-LD) into calendar events on its own. A person writing a
 * normal email does neither, so this reads the words: a time, optionally a
 * date and an address, and a word that says it is a plan ("meet", "lunch",
 * "appointment"…). What it finds becomes a card on the mail with an
 * "Add to calendar" button that opens the event editor pre-filled.
 *
 * NOTHING is put in the calendar by this class. Words are ambiguous, so the
 * user checks the editor and presses Save — that is the safety catch.
 *
 * Deliberately conservative: a time is required, and either a plan word, a
 * date or an address must sit with it. Newsletters full of "sale ends 10pm"
 * are kept out by also skipping bulk mail (a List-Unsubscribe-style footer is
 * not checked, but mail with no plan word and no address never qualifies).
 */
final readonly class MeetingExtractor implements InsightExtractorInterface
{
    /** Words that make a mail about getting together. Lowercase, substring match. */
    private const array PLAN_WORDS = [
        'meet', 'meeting', 'appointment', 'appt', 'lunch', 'dinner', 'breakfast', 'brunch',
        'coffee', 'drinks', 'interview', 'party', 'pick up', 'pickup', 'pick you up',
        'see you', 'see ya', 'call', 'visit', 'reservation', 'booked', 'schedule',
        'come by', 'come over', 'stop by', 'hang out', 'hangout', 'date night',
        'treffen', 'termin', 'verabredung',
    ];

    private const array MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    private const array WEEKDAYS = [
        'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
    ];

    /** Bodies longer than this are newsletters, not plans. */
    private const int MAX_BODY = 6000;

    public function __construct(private UserTimezoneResolver $timezones)
    {
    }

    public static function key(): string
    {
        return 'meeting';
    }

    public function icon(): string
    {
        return InsightKind::Meeting->icon();
    }

    /** Below codes, flights and parcels: a booking mail is better read by those. */
    public function priority(): int
    {
        return 40;
    }

    public function supports(Message $message): bool
    {
        return null !== $message->receivedAt;
    }

    public function extract(Message $message): array
    {
        $subject = trim((string) $message->subject);
        $body    = ReadableBody::of($message);

        if (mb_strlen($body) > self::MAX_BODY) {
            return [];
        }

        $zone = $this->timezones->resolve($message->account->usr ?? null);
        $plan = self::parse($subject . "\n" . $body, $message->receivedAt->setTimezone($zone));

        if (null === $plan) {
            return [];
        }

        $title = self::titleFor($subject, (string) ($message->fromName ?: $message->fromAddress));

        $from  = trim((string) $message->fromName) !== ''
            ? sprintf('%s <%s>', $message->fromName, $message->fromAddress)
            : (string) $message->fromAddress;

        $start = $plan['hasTime'] ? $plan['start']->format('Y-m-d\TH:i') : $plan['start']->format('Y-m-d');

        return [new InsightDraft(
            kind: InsightKind::Meeting,
            title: $title,
            dedupeKey: 'meeting|' . sha1(mb_strtolower($title) . '|' . $start),
            payload: [
                'start'       => $start,
                'hasTime'     => $plan['hasTime'],
                'location'    => $plan['location'],
                'description' => trim(sprintf("From an email by %s:\n%s", $from, $plan['sentence'])),
            ],
            happensAt: $plan['start']->setTimezone(new DateTimeZone('UTC')),
        )];
    }

    /**
     * Reads a plan out of text. Public and static so it can be tested without a
     * database.
     *
     * @return array{start: DateTimeImmutable, hasTime: bool, location: ?string, sentence: string}|null
     */
    public static function parse(string $text, DateTimeImmutable $received): ?array
    {
        $flat  = preg_replace('~[ \t\x{00A0}]+~u', ' ', $text) ?? $text;
        $lower = mb_strtolower($flat);

        $time     = self::findTime($flat);
        $date     = self::findDate($flat, $received);
        // Addresses are often wrapped onto two lines by the sender's mail app
        // ("218 All America City\nHwy, Lindsay, CA"), so they are read with
        // line breaks flattened.
        $location = self::findAddress(preg_replace('~\s*\n\s*~u', ' ', $flat) ?? $flat);
        $hasPlan  = self::mentionsPlan($lower);

        // A time or date alone is everywhere ("sale ends tonight at 10pm"):
        // it only counts next to a plan word or an actual place.
        if (null === $time && null === $date) {
            return null;
        }
        if (false === $hasPlan && null === $location) {
            return null;
        }
        if (null === $time && (null === $location || false === $hasPlan)) {
            // A date with no time needs both a place and a plan word.
            return null;
        }

        $day = $date ?? $received->setTime(0, 0);

        if (null !== $time) {
            $start = $day->setTime($time[0], $time[1]);

            // No date given: the next time that clock comes round after the mail.
            if (null === $date && $start <= $received) {
                $start = $start->modify('+1 day');
            }
        } else {
            $start = $day;
        }

        // Plans are about the future; a date already well past is a story.
        if ($start < $received->modify('-1 day')) {
            return null;
        }

        return [
            'start'    => $start,
            'hasTime'  => null !== $time,
            'location' => $location,
            'sentence' => self::sentenceAround($flat, $time[2] ?? $date?->format('Y-m-d') ?? ''),
        ];
    }

    /** @return array{0:int,1:int,2:string}|null hour, minute, matched text */
    private static function findTime(string $text): ?array
    {
        if (1 === preg_match('~\b(1[0-2]|0?[1-9])(?::([0-5]\d))?\s*(a\.?m\.?|p\.?m\.?)(?![a-z])~iu', $text, $m)) {
            $hour = (int) $m[1] % 12;
            if (str_starts_with(strtolower($m[3]), 'p')) {
                $hour += 12;
            }

            return [$hour, (int) ($m[2] ?? 0), $m[0]];
        }

        if (1 === preg_match('~\b(noon|midday)\b~iu', $text, $m)) {
            return [12, 0, $m[0]];
        }

        // 24-hour times only with an "at"/"um"/"@" in front, so "3:2 ratio" and
        // scores stay out.
        if (1 === preg_match('~(?:\bat|\bum|@)\s*([01]?\d|2[0-3]):([0-5]\d)\b~iu', $text, $m)) {
            return [(int) $m[1], (int) $m[2], $m[0]];
        }

        return null;
    }

    private static function findDate(string $text, DateTimeImmutable $received): ?DateTimeImmutable
    {
        $year  = (int) $received->format('Y');
        $today = $received->setTime(0, 0);

        // "October 12", "Oct 12th, 2026", "Oct. 12"
        if (1 === preg_match('~\b(jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*\.?\s+(\d{1,2})(?:st|nd|rd|th)?\b(?:,?\s*(\d{4}))?~iu', $text, $m)) {
            return self::dayOf($today, (int) ($m[3] ?? 0) ?: $year, self::MONTHS[substr(strtolower($m[1]), 0, 3)], (int) $m[2], empty($m[3]));
        }

        // "12 October", "12th of Oct"
        if (1 === preg_match('~\b(\d{1,2})(?:st|nd|rd|th)?\s+(?:of\s+)?(jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*\b(?:,?\s*(\d{4}))?~iu', $text, $m)) {
            return self::dayOf($today, (int) ($m[3] ?? 0) ?: $year, self::MONTHS[substr(strtolower($m[2]), 0, 3)], (int) $m[1], empty($m[3]));
        }

        // ISO 2026-10-12
        if (1 === preg_match('~\b(20\d\d)-(\d{1,2})-(\d{1,2})\b~', $text, $m)) {
            return self::dayOf($today, (int) $m[1], (int) $m[2], (int) $m[3], false);
        }

        // US 10/12 or 10/12/2026 (month first — this is a US install)
        if (1 === preg_match('~\b(1[0-2]|0?[1-9])/([12]\d|3[01]|0?[1-9])(?:/(\d{2}|\d{4}))?\b~', $text, $m)) {
            $y = isset($m[3]) && '' !== $m[3] ? (int) $m[3] : $year;
            if ($y < 100) {
                $y += 2000;
            }

            return self::dayOf($today, $y, (int) $m[1], (int) $m[2], !isset($m[3]) || '' === $m[3]);
        }

        $lower = mb_strtolower($text);

        if (1 === preg_match('~\b(tomorrow|tmrw|tmr)\b~u', $lower)) {
            return $today->modify('+1 day');
        }
        if (1 === preg_match('~\b(today|tonight|this evening|this afternoon|this morning)\b~u', $lower)) {
            return $today;
        }

        // "on Friday", "next Friday", "this Saturday"
        if (1 === preg_match('~\b(next|this|on|coming)?\s*(' . implode('|', self::WEEKDAYS) . ')\b~u', $lower, $m)) {
            // "next Friday" and "on Friday" both mean the coming one.
            return $today->modify('next ' . $m[2]);
        }

        return null;
    }

    private static function dayOf(DateTimeImmutable $today, int $year, int $month, int $day, bool $yearGuessed): ?DateTimeImmutable
    {
        if (false === checkdate($month, $day, $year)) {
            return null;
        }

        $date = $today->setDate($year, $month, $day);

        // "Jan 3" written in December is next year's; "Sept 3" written in
        // October is a story about last month and is dropped by the caller.
        if ($yearGuessed && $date < $today->modify('-180 days')) {
            $date = $date->modify('+1 year');
        }

        return $date;
    }

    /** A US-style street address, or the line after "Address:" / "Location:". */
    private static function findAddress(string $text): ?string
    {
        // 218 All America City Hwy, Olivewood Plaza, Lindsay, CA 93247
        if (1 === preg_match('~\b\d{1,6}\s+[A-Za-z0-9][A-Za-z0-9 .\'#&-]{2,60}(?:,\s*[A-Za-z0-9][A-Za-z0-9 .\'#&-]{1,60}){0,3},?\s+[A-Z]{2}\s+\d{5}(?:-\d{4})?\b~u', $text, $m)) {
            // The greedy match can start too early ("Oct 20 at 3 pm, 455 Main
            // St, …"): begin at the first segment that looks like a street —
            // a number then a word, and no clock time in it.
            $segments = explode(',', $m[0]);
            foreach ($segments as $i => $segment) {
                if (1 === preg_match('~^\s*\d{1,6}\s+[A-Za-z]~', $segment)
                    && 0 === preg_match('~\b(am|pm|at)\b~i', $segment)) {
                    return self::tidy(implode(',', array_slice($segments, $i)));
                }
            }

            return null;
        }

        if (1 === preg_match('~\b(?:address|location|where|place|venue|adresse|ort)\s*[:\-]\s*([^\n]{4,160})~iu', $text, $m)) {
            // Stop at the next labelled field on the same line ("… Phone: …").
            $line = preg_split('~\s+(?:phone|tel|telephone|time|when|date|at\s+\d)\b\s*[:\-]?|[.!?](?:\s|$)~i', $m[1])[0];

            return '' === trim($line) ? null : self::tidy($line);
        }

        return null;
    }

    private static function mentionsPlan(string $lower): bool
    {
        foreach (self::PLAN_WORDS as $word) {
            if (1 === preg_match('~\b' . preg_quote($word, '~') . '~u', $lower)) {
                return true;
            }
        }

        return false;
    }

    /** The sentence the time (or date) sits in, for the event's note. */
    private static function sentenceAround(string $text, string $needle): string
    {
        $pos = '' === $needle ? false : mb_stripos($text, $needle);
        if (false === $pos) {
            return self::tidy(mb_substr($text, 0, 240));
        }

        $start = max(0, $pos - 160);
        $chunk = mb_substr($text, $start, 320);

        return self::tidy(($start > 0 ? '…' : '') . $chunk . (mb_strlen($text) > $start + 320 ? '…' : ''));
    }

    private static function titleFor(string $subject, string $sender): string
    {
        $clean = trim(preg_replace('~^\s*((re|fwd?|aw|wg)\s*:\s*)+~iu', '', $subject) ?? $subject);

        if ('' !== $clean) {
            return mb_substr($clean, 0, 200);
        }

        return mb_substr('Meet ' . trim($sender), 0, 200);
    }

    private static function tidy(string $value): string
    {
        return trim(preg_replace('~\s+~u', ' ', $value) ?? $value, " \t\n\r\0\x0B,.;");
    }
}
