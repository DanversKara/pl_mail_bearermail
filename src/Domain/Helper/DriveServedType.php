<?php

declare(strict_types=1);

namespace App\Domain\Helper;

/**
 * The Content-Type a Drive file is served under.
 *
 * A Drive file's type is whatever the uploader's browser or app said, and the
 * file is served from the app's own origin. Labelled `text/javascript`, it
 * would satisfy the page CSP's `script-src 'self'`: any markup that reached
 * the app's document could load it with `<script src>`, and Content-Disposition
 * does nothing for a subresource. A shared link makes that anyone's upload.
 * So only types a browser shows without running anything keep their label;
 * everything else is bytes, as mail attachments are (InlineDisposition).
 */
final class DriveServedType
{
    public const array SAFE = [
        'image/png', 'image/jpeg', 'image/gif', 'image/webp',
        'application/pdf', 'text/plain', 'video/mp4', 'audio/mpeg',
    ];

    public static function isSafe(?string $mimeType): bool
    {
        return \in_array(self::normalise($mimeType), self::SAFE, true);
    }

    public static function of(?string $mimeType): string
    {
        return true === self::isSafe($mimeType) ? self::normalise($mimeType) : 'application/octet-stream';
    }

    private static function normalise(?string $mimeType): string
    {
        return strtolower(trim(explode(';', (string) $mimeType)[0]));
    }
}
