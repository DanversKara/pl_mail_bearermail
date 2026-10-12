<?php

declare(strict_types=1);

namespace App\Tests\Service\Insight\Extractor;

use App\Service\Insight\Extractor\MeetingExtractor;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * BearerMail: plans read from plain words — and the header lines of a
 * forward or reply, which name when the OLD mail was sent, kept out.
 */
final class MeetingExtractorTest extends TestCase
{
    private const string RECEIVED = '2026-10-11 20:20';

    public function testAPlanWithAnAddressIsRead(): void
    {
        $plan = $this->parse("Lunch?\nMeet me at 218 Main St, Lindsay, CA 93247 on Friday at 10am");

        self::assertNotNull($plan);
        self::assertSame('2026-10-16 10:00', $plan['start']->format('Y-m-d H:i'));
        self::assertSame('218 Main St, Lindsay, CA 93247', $plan['location']);
    }

    public function testADateLineInAnInvitationStays(): void
    {
        $plan = $this->parse("Party\nDate: Saturday at 7pm\nWhere: our place");

        self::assertNotNull($plan);
        self::assertSame('2026-10-17 19:00', $plan['start']->format('Y-m-d H:i'));
    }

    public function testAForwardedHeaderIsNotAPlan(): void
    {
        $text = "Fwd: Your Play Console verification code\n\n"
            . "---------- Forwarded message ----------\n"
            . "From: Google <noreply@google.com>\n"
            . "Date: Mon, Oct 12, 2026 at 3:00 am\n"
            . "Subject: Your Play Console verification code\n"
            . "To: someone@example.com\n\n"
            . "To verify your email address in Play Console, enter the following code:\n\n959528\n\n"
            . "Call us or visit the help center.";

        self::assertNull($this->parse($text));
    }

    public function testAReplyAttributionIsNotThePlan(): void
    {
        $plan = $this->parse("Re: dinner\nSee you tomorrow at 6:30 pm!\n\nOn Sat, Oct 10, 2026 at 3:00 PM Bob <b@example.com> wrote:\n> want to meet?");

        self::assertNotNull($plan);
        self::assertSame('2026-10-12 18:30', $plan['start']->format('Y-m-d H:i'));
    }

    /** @return array{start: DateTimeImmutable, hasTime: bool, location: ?string, sentence: string}|null */
    private function parse(string $text): ?array
    {
        return MeetingExtractor::parse(MeetingExtractor::withoutQuoteHeaders($text), new DateTimeImmutable(self::RECEIVED));
    }
}
