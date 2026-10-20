<?php

declare(strict_types=1);

namespace App\Tests\Service\Drive;

use App\Entity\Drive\DriveFile;
use App\Entity\User\User;
use App\Service\Drive\DrivePreviewer;
use App\Service\Drive\DriveStorage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DrivePreviewerTest extends TestCase
{
    private string $root;
    private DriveStorage $storage;
    private DrivePreviewer $previewer;
    private User $user;
    private int $nextId = 1;

    protected function setUp(): void
    {
        $this->root      = sys_get_temp_dir() . '/drive-preview-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        $this->storage   = new DriveStorage($this->root, 'store');
        $this->previewer = new DrivePreviewer($this->storage, new NullLogger(), $this->root, 'store');
        $this->user      = new User();
        $this->setId($this->user, 7);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testAPictureGetsAScaledDownPreview(): void
    {
        $image = imagecreatetruecolor(1200, 900);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 30, 30));
        ob_start();
        imagepng($image);
        $file = $this->file('Screenshot.png', 'image/png', (string) ob_get_clean());

        self::assertSame('picture', $this->previewer->kind($file));
        $path = $this->previewer->imagePath($file);
        self::assertNotNull($path);
        [$w, $h] = getimagesize($path);
        self::assertLessThanOrEqual(420, max($w, $h));
        self::assertSame($path, $this->previewer->imagePath($file), 'cached');

        $this->previewer->forget($file);
        self::assertFileDoesNotExist($path);
    }

    public function testTextShowsItsOpeningLines(): void
    {
        $file = $this->file('notes.md', 'text/markdown', "# Shopping\n\n- eggs\n- milk\n" . str_repeat("more\n", 100));

        self::assertSame('text', $this->previewer->kind($file));
        $excerpt = (string) $this->previewer->excerpt($file);
        self::assertStringStartsWith("# Shopping\n\n- eggs", $excerpt);
        self::assertLessThanOrEqual(16, substr_count($excerpt, "\n") + 1);
        self::assertNull($this->previewer->imagePath($file));
    }

    public function testAWordDocumentShowsItsParagraphs(): void
    {
        $zipPath = $this->root . '/doc.docx';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('word/document.xml', '<w:document xmlns:w="w"><w:body><w:p><w:r><w:t>Dear Anna,</w:t></w:r></w:p><w:p><w:r><w:t>Fish &amp; chips at 6.</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();

        $file = $this->file('Letter.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', (string) file_get_contents($zipPath));

        self::assertSame("Dear Anna,\nFish & chips at 6.", $this->previewer->excerpt($file));
    }

    public function testAPdfShowsItsFirstPageWhenPopplerIsThere(): void
    {
        if ('' === trim((string) shell_exec('command -v pdftoppm'))) {
            self::markTestSkipped('pdftoppm is not installed here');
        }

        $file = $this->file('Bill.pdf', 'application/pdf', self::onePagePdf());
        $path = $this->previewer->imagePath($file);

        self::assertNotNull($path);
        self::assertSame(IMAGETYPE_WEBP, getimagesize($path)[2]);
    }

    public function testNothingIsDrawnForAnSvg(): void
    {
        $file = $this->file('logo.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        self::assertNull($this->previewer->kind($file));
        self::assertNull($this->previewer->imagePath($file));
        self::assertNull($this->previewer->excerpt($file));
    }

    private function file(string $name, string $mime, string $content): DriveFile
    {
        $file = DriveFile::create($this->user, $name, $mime, \strlen($content));
        $this->setId($file, $this->nextId++);
        $this->storage->storeContent($content, $file);

        return $file;
    }

    private function setId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);
    }

    private static function onePagePdf(): string
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>',
        ];
        $pdf     = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[] = \strlen($pdf);
            $pdf .= sprintf("%d 0 obj\n%s\nendobj\n", $i + 1, $body);
        }
        $xref = \strlen($pdf);
        $pdf .= sprintf("xref\n0 %d\n0000000000 65535 f \n", \count($objects) + 1);
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf . sprintf("trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n", \count($objects) + 1, $xref);
    }
}
