<?php

declare(strict_types=1);

namespace App\Service\Mail;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Takes out of a `style` attribute the declarations that let an element leave
 * the place it was put.
 *
 * The sanitiser keeps inline styles, because in mail they ARE the design. In
 * the reading pane that costs nothing: the message is in a sandboxed frame and
 * can only rearrange itself. But a message is also shown in the app's own
 * document — quoted in the reply composer, and on the print page — and there
 * `position: fixed` is not a layout choice, it is a way to draw over plMail: a
 * mail could cover the composer with a page of its own the moment somebody
 * pressed Reply (issue #34).
 *
 * Two strengths, because the two places differ in what they can afford:
 *
 *   everywhere   `position: fixed` and `sticky`. Neither means anything in
 *                mail — there is no viewport to be fixed to in a message — so
 *                nothing genuine is lost by dropping them at ingest.
 *
 *   composing    `position: absolute` and `z-index` as well. Absolutely
 *                positioned content with no positioned ancestor is placed
 *                against the page, which in the composer is the app. Some real
 *                newsletters do position absolutely, which is why this half is
 *                not applied to mail that is only being read.
 *
 * On top of both, at every strength, declarations that try to run code
 * through CSS are dropped outright: `javascript:`/`vbscript:` URLs,
 * `expression(...)`, and the `behavior`/`-moz-binding` properties. No modern
 * browser executes any of these from a stylesheet, and no genuine mail uses
 * them — but they are the one thing in a style attribute that is code rather
 * than paint, so they do not survive ingest even though the frame CSP would
 * refuse them anyway.
 *
 * What this deliberately does NOT do is strip remote `url(...)` references:
 * those are tracking, not code, and RemoteContentBlocker already rewrites
 * them at render time (proxied when remote content is allowed, `none` when
 * it is not). Stripping them here would break trusted senders' backgrounds
 * that the blocker would otherwise proxy.
 *
 * This is one of two layers. The other is in the stylesheets: the composer's
 * editor and the print page's body are containing blocks that clip, so a
 * declaration that got past this — in a body stored before it existed, or
 * written some way this pattern does not anticipate — still cannot reach
 * outside its own box.
 */
final readonly class EscapingStyleAttributeSanitizer implements AttributeSanitizerInterface
{
    public function __construct(
        private bool $composing = false,
    ) {
    }

    public function getSupportedElements(): ?array
    {
        return null;
    }

    public function getSupportedAttributes(): array
    {
        return ['style'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): string
    {
        $kept = [];

        foreach (explode(';', $value) as $declaration) {
            if ('' === trim($declaration) || true === $this->escapes($declaration)) {
                continue;
            }

            $kept[] = trim($declaration);
        }

        return implode('; ', $kept);
    }

    private function escapes(string $declaration): bool
    {
        [$property, $rest] = array_pad(explode(':', $declaration, 2), 2, '');

        // Compared without comments, whitespace or case: `pos/**/ition` and
        // `POSITION : Fixed` are the same declaration to a browser.
        $property = strtolower((string) preg_replace('~/\*.*?\*/|\s+~s', '', $property));
        $rest     = strtolower((string) preg_replace('~/\*.*?\*/|\s+~s', '', $rest));

        if ('position' === $property) {
            return true === str_contains($rest, 'fixed')
                || true === str_contains($rest, 'sticky')
                || (true === $this->composing && true === str_contains($rest, 'absolute'));
        }

        if (true === $this->composing && 'z-index' === $property) {
            return true;
        }

        // Code smuggled as paint. Dead in every modern browser, dropped
        // anyway: a style attribute is the one place in a sanitised body
        // where a value can still name a script, and nothing genuine is
        // lost by refusing it here rather than trusting the frame CSP to.
        return 'behavior' === $property
            || '-moz-binding' === $property
            || true === str_contains($rest, 'javascript:')
            || true === str_contains($rest, 'vbscript:')
            || true === str_contains($rest, 'expression(');
    }
}
