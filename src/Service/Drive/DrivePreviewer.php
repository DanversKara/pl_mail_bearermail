<?php

declare(strict_types=1);

namespace App\Service\Drive;

use App\Entity\Drive\DriveFile;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * BearerMail: what a Drive tile shows instead of a bare icon.
 *
 *   - picture  — the picture itself, scaled down (GD: JPEG, PNG, GIF, WebP).
 *   - pdf      — its first page, drawn by poppler's `pdftoppm` when the image
 *                has it (the Dockerfile installs it); the icon otherwise.
 *   - text     — the opening lines, drawn by the template like a little page:
 *                plain text, Markdown, CSV, code, and the words of a Word
 *                (.docx) or LibreOffice (.odt) document.
 *
 * Pictures and pages are made once and cached beside the Drive files, under
 * `drive/_thumbs/<user>/`, keyed by the file's id, size and modification time,
 * so a replaced file gets a new one. Nothing here runs a file's own code: SVG
 * and HTML are not drawn, and text is shown as text.
 */
final readonly class DrivePreviewer
{
    private const array PICTURES = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    private const array TEXTS = [
        'txt', 'md', 'markdown', 'csv', 'tsv', 'log', 'json', 'xml', 'yaml', 'yml', 'ini', 'conf', 'cfg', 'env',
        'php', 'js', 'ts', 'css', 'py', 'sh', 'kt', 'java', 'c', 'h', 'cpp', 'rs', 'go', 'sql', 'rb', 'swift', 'vcf', 'ics',
    ];

    private const array DOCUMENTS = ['docx' => 'word/document.xml', 'odt' => 'content.xml'];

    /** Above these, a preview costs more than it is worth: the icon it is. */
    private const int MAX_PICTURE_BYTES = 25 * 1024 * 1024;

    private const int MAX_PIXELS = 50_000_000;

    private const int MAX_PDF_BYTES = 60 * 1024 * 1024;

    private const int MAX_DOCUMENT_BYTES = 30 * 1024 * 1024;

    /** Twice a grid tile's width, for sharp screens. */
    private const int EDGE = 420;

    private const int EXCERPT_BYTES = 6000;

    private const int EXCERPT_CHARS = 700;

    private const int EXCERPT_LINES = 16;

    public function __construct(
        private DriveStorage $storage,
        private LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        #[Autowire('%env(APP_STORAGE_DIR)%')]
        private string $storageDir,
    ) {
    }

    /** 'picture', 'pdf', 'text' or null — what the tile can show. Reads no file. */
    public function kind(DriveFile $file): ?string
    {
        $ext = strtolower($file->extension());

        return match (true) {
            \in_array($ext, self::PICTURES, true) && $file->sizeBytes <= self::MAX_PICTURE_BYTES && \function_exists('imagecreatetruecolor') => 'picture',
            'pdf' === $ext && $file->sizeBytes <= self::MAX_PDF_BYTES && \function_exists('imagecreatetruecolor') => 'pdf',
            \in_array($ext, self::TEXTS, true), isset(self::DOCUMENTS[$ext]) && $file->sizeBytes <= self::MAX_DOCUMENT_BYTES => 'text',
            default => null,
        };
    }

    /**
     * The cached WebP for a picture or a PDF's first page, made on first call.
     * Null when there is none to be had — the tile keeps its icon.
     */
    public function imagePath(DriveFile $file): ?string
    {
        $kind = $this->kind($file);
        if ('picture' !== $kind && 'pdf' !== $kind) {
            return null;
        }

        $source = $this->storage->path($file);
        if (false === is_file($source)) {
            return null;
        }

        $cache = sprintf(
            '%s/%s/drive/_thumbs/%d/%d-%d-%d.webp',
            $this->projectDir,
            $this->storageDir,
            $file->usr->id,
            $file->id,
            $file->sizeBytes,
            (int) filemtime($source),
        );
        if (is_file($cache)) {
            return $cache;
        }

        try {
            return 'pdf' === $kind ? $this->fromPdf($source, $cache) : $this->fromPicture($source, $cache);
        } catch (\Throwable $e) {
            $this->logger->info('Drive preview failed', ['file' => $file->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * The opening lines of a text file or document, cleaned to readable text.
     * Null for anything else, or when there is nothing to show.
     */
    public function excerpt(DriveFile $file): ?string
    {
        if ('text' !== $this->kind($file)) {
            return null;
        }

        $source = $this->storage->path($file);
        if (false === is_file($source)) {
            return null;
        }

        try {
            $ext  = strtolower($file->extension());
            $text = isset(self::DOCUMENTS[$ext])
                ? $this->documentText($source, self::DOCUMENTS[$ext])
                : (string) @file_get_contents($source, false, null, 0, self::EXCERPT_BYTES);
        } catch (\Throwable) {
            return null;
        }

        return $this->tidy($text);
    }

    /** Removes the cached previews of a file, e.g. when it is deleted. */
    public function forget(DriveFile $file): void
    {
        $pattern = sprintf('%s/%s/drive/_thumbs/%d/%d-*.webp', $this->projectDir, $this->storageDir, $file->usr->id, $file->id);
        foreach (glob($pattern) ?: [] as $path) {
            @unlink($path);
        }
    }

    private function fromPicture(string $source, string $cache): ?string
    {
        $info = @getimagesize($source);
        if (false === $info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            return null;
        }

        $image = @imagecreatefromstring((string) file_get_contents($source));
        if (false === $image) {
            return null;
        }

        return $this->writeScaled($image, $cache);
    }

    private function fromPdf(string $source, string $cache): ?string
    {
        $base = sys_get_temp_dir() . '/drive-pdf-' . bin2hex(random_bytes(6));
        $process = new Process(
            ['pdftoppm', '-f', '1', '-l', '1', '-singlefile', '-png', '-scale-to', (string) self::EDGE, $source, $base],
            timeout: 20,
        );

        try {
            $process->run();
        } catch (\Throwable) {
            return null; // No pdftoppm on this install: the icon it is.
        }

        $png = $base . '.png';
        try {
            if (false === $process->isSuccessful() || false === is_file($png)) {
                return null;
            }
            $image = @imagecreatefrompng($png);

            return false === $image ? null : $this->writeScaled($image, $cache);
        } finally {
            @unlink($png);
        }
    }

    private function writeScaled(\GdImage $image, string $cache): ?string
    {
        $width  = imagesx($image);
        $height = imagesy($image);
        $scale  = min(self::EDGE / $width, self::EDGE / $height, 1);
        $target = imagescale($image, max(1, (int) round($width * $scale)));
        if (false === $target) {
            return null;
        }

        $dir = \dirname($cache);
        if (false === is_dir($dir) && false === @mkdir($dir, 0750, true) && false === is_dir($dir)) {
            return null;
        }

        $temporary = $cache . '.' . bin2hex(random_bytes(4));
        if (false === imagewebp($target, $temporary, 78)) {
            @unlink($temporary);

            return null;
        }
        rename($temporary, $cache);

        return $cache;
    }

    /** The words of a .docx or .odt, paragraph by paragraph. */
    private function documentText(string $source, string $entry): string
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($source, \ZipArchive::RDONLY)) {
            return '';
        }

        try {
            $stat = $zip->statName($entry);
            if (false === $stat || $stat['size'] > 20 * 1024 * 1024) {
                return '';
            }
            $xml = (string) $zip->getFromName($entry);
        } finally {
            $zip->close();
        }

        // Paragraph ends and line breaks become new lines; tabs a space.
        $xml = (string) preg_replace('#</(w:p|text:p|text:h)>|<(w:br|text:line-break)[^>]*/>#', "\n", $xml);
        $xml = (string) preg_replace('#<(w:tab|text:tab)[^>]*/>#', ' ', $xml);

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function tidy(string $text): ?string
    {
        if ('' === $text) {
            return null;
        }
        if (false === mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        $text = (string) preg_replace('/^\xEF\xBB\xBF/', '', $text);
        $text = (string) preg_replace('/[^\P{C}\n\t]/u', '', str_replace("\r\n", "\n", $text));
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        $lines = \array_slice(explode("\n", trim($text)), 0, self::EXCERPT_LINES);
        $out   = mb_substr(implode("\n", $lines), 0, self::EXCERPT_CHARS);

        return '' === trim($out) ? null : $out;
    }
}
