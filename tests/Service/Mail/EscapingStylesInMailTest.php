<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Service\Mail\MailBodySanitizer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A mail's inline styles cannot take its content out of the message.
 *
 * Inline styles are kept on purpose — in mail they are the design — and the
 * reading pane can afford that because it is a sandboxed frame. The reply
 * composer and the print page are not: there the sender's HTML sits in the
 * app's own document, and `position: fixed` on a full-size element drew the
 * sender's page over plMail when the reader pressed Reply (issue #34).
 */
final class EscapingStylesInMailTest extends TestCase
{
    private MailBodySanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new MailBodySanitizer(self::createStub(UrlGeneratorInterface::class), new NullLogger());
    }

    public function testAFixedElementLosesItsPositionAndKeepsItsOtherStyles(): void
    {
        $html = $this->sanitizer->sanitizeFragment(
            '<div style="position:fixed; top:0; left:0; color: red">Sign in again</div>',
        );

        self::assertStringNotContainsString('position', $html);
        self::assertStringContainsString('color: red', $html);
        self::assertStringContainsString('Sign in again', $html);
    }

    /** The spellings a pattern match on "position:fixed" would wave through. */
    public function testTheDeclarationIsRecognisedHoweverItIsWritten(): void
    {
        foreach (['POSITION : Fixed', 'position:/**/fixed', "position:\tsticky", 'position: fixed !important'] as $declaration) {
            $html = $this->sanitizer->sanitizeFragment(sprintf('<p style="margin: 0; %s">x</p>', $declaration));

            self::assertStringNotContainsStringIgnoringCase('fixed', $html, $declaration);
            self::assertStringNotContainsStringIgnoringCase('sticky', $html, $declaration);
            self::assertStringContainsString('margin: 0', $html, $declaration);
        }
    }

    /**
     * Mail that is only read keeps absolute positioning — real newsletters use
     * it, and in the frame it can only rearrange the message. A body being
     * composed is in the app's document, where "absolute" with no positioned
     * ancestor means "against the page".
     */
    public function testAbsolutePositioningSurvivesReadingAndNotComposing(): void
    {
        $body = '<div style="position:absolute; z-index: 99999; inset: 0">x</div>';

        self::assertStringContainsString('absolute', $this->sanitizer->sanitizeFragment($body));

        $composed = $this->sanitizer->sanitizeComposedBody($body);

        self::assertStringNotContainsString('absolute', $composed);
        self::assertStringNotContainsString('z-index', $composed);
        self::assertStringContainsString('inset: 0', $composed);
    }

    public function testOrdinaryStylesAreUntouched(): void
    {
        $html = $this->sanitizer->sanitizeFragment('<div style="padding: 12px; background-position: center; position: relative">x</div>');

        self::assertStringContainsString('padding: 12px', $html);
        self::assertStringContainsString('background-position: center', $html);
        self::assertStringContainsString('position: relative', $html);
    }

    /** Code smuggled as paint is dropped at ingest, at either strength. */
    public function testCodeInStylesIsDropped(): void
    {
        $html = $this->sanitizer->sanitizeFragment(
            '<div style="color: red; background: url(javascript:alert(1)); width: expression(alert(1)); behavior: url(x.htc)">x</div>',
        );

        self::assertStringContainsString('color: red', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringNotContainsString('expression(', $html);
        self::assertStringNotContainsString('behavior', $html);
    }

    /**
     * Remote images in styles are NOT stripped here: that is tracking, not
     * code, and RemoteContentBlocker rewrites it at render time (proxied
     * when allowed, `none` when blocked). Stripping it at ingest would
     * break trusted senders' backgrounds the blocker would otherwise proxy.
     */
    public function testRemoteStyleUrlsSurviveIngestForTheBlocker(): void
    {
        $html = $this->sanitizer->sanitizeFragment(
            '<div style="background: url(https://example.com/bg.png)">x</div>',
        );

        self::assertStringContainsString('example.com/bg.png', $html);
    }
}
