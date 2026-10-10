<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Helper\DomainHelper;

/**
 * Links whose visible text claims a different destination than their href.
 *
 * The classic phish: `<a href="https://attacker.example">bank.example</a>`. The text
 * looks like a domain the reader trusts; the href goes elsewhere. This
 * checker finds those — where the link TEXT parses as a domain (or URL) and
 * its registrable domain differs from the href's.
 *
 * Only flags when the text really looks like a URL. "Click here" linking
 * somewhere is normal; "bank.example" linking to attacker.example is not.
 */
final readonly class DeceptiveLinkChecker
{
    /**
     * @return list<array{text: string, href: string, textDomain: string, hrefDomain: string}>
     */
    public function check(?string $html): array
    {
        if (null === $html || '' === trim($html)) {
            return [];
        }

        try {
            $document = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
        } catch (\Throwable) {
            return [];
        }

        $body = $document->body;
        if (null === $body) {
            return [];
        }

        $deceptive = [];
        foreach ($body->getElementsByTagName('a') as $link) {
            $href = trim($link->getAttribute('href'));
            $text = trim($link->textContent);

            if ('' === $href || '' === $text) {
                continue;
            }

            // Only http(s) links matter.
            if (false === str_starts_with(strtolower($href), 'http')) {
                continue;
            }

            $hrefDomain = $this->registrableOfUrl($href);
            $textDomain = $this->registrableOfText($text);

            // Text isn't a domain/URL — nothing to compare.
            if (null === $textDomain || null === $hrefDomain) {
                continue;
            }

            if ($textDomain !== $hrefDomain) {
                $deceptive[] = [
                    'text'       => $text,
                    'href'       => $href,
                    'textDomain' => $textDomain,
                    'hrefDomain' => $hrefDomain,
                ];
            }
        }

        return $deceptive;
    }

    private function registrableOfUrl(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (false === is_string($host) || '' === $host) {
            return null;
        }

        return DomainHelper::registrable($host);
    }

    /**
     * If the link text looks like a domain or URL, return its registrable
     * domain. Otherwise null (normal link text like "Click here").
     */
    private function registrableOfText(string $text): ?string
    {
        // Strip common URL prefixes the text might carry.
        $clean = preg_replace('#^https?://#i', '', $text);
        $clean = preg_replace('#^www\.#i', '', $clean);
        // Take just the host part if there's a path.
        $clean = explode('/', $clean)[0];
        $clean = trim($clean);

        // Must look like a domain: has a dot, no spaces, plausible characters.
        if (false === (bool) preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+$/i', $clean)) {
            return null;
        }

        return DomainHelper::registrable($clean);
    }
}
