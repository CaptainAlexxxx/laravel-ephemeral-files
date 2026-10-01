<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\After;
use ZipArchive;

/**
 * Builds real file bytes so content-sniffing in StoreFileRequest actually runs,
 * instead of UploadedFile::fake()->create() which fakes the mime.
 */
trait MakesFileFixtures
{
    /** @var list<string> */
    private array $tempFixturePaths = [];

    #[After]
    protected function cleanUpTempFixtures(): void
    {
        foreach ($this->tempFixturePaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->tempFixturePaths = [];
    }

    protected function minimalPdfBytes(): string
    {
        return <<<'PDF'
%PDF-1.4
1 0 obj
<< /Type /Catalog /Pages 2 0 R >>
endobj
2 0 obj
<< /Type /Pages /Kids [3 0 R] /Count 1 >>
endobj
3 0 obj
<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>
endobj
trailer
<< /Size 4 /Root 1 0 R >>
%%EOF
PDF;
    }

    protected function minimalDocxBytes(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Hello</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();

        $bytes = file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    /**
     * A valid DOCX with a few KB of docProps/ before word/, which libmagic reads as plain zip.
     */
    protected function docxBytesWithDocPropsFirst(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"/>');
        // incompressible bytes push word/ past the window libmagic searches
        $zip->addFromString('docProps/thumbnail.jpeg', random_bytes(8192));
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Hello</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();

        $bytes = file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    /**
     * A ZIP that is not a Word document: libmagic reports plain application/zip.
     */
    protected function genericZipBytes(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('readme.txt', 'just a plain zip, not a docx');
        $zip->close();

        $bytes = file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    /**
     * Illuminate\Http\Testing\File (what UploadedFile::fake()->createWithContent() returns) overrides
     * getMimeType() to guess from the filename extension, never touching real content. To exercise the
     * app's real finfo-based sniffing, wrap a real temp file in a plain UploadedFile with test mode on.
     *
     * The backing temp file has to outlive this method call for the request to read it, so it is
     * tracked here and removed in cleanUpTempFixtures() instead of being unlinked immediately.
     */
    protected function uploadedFileFromBytes(string $bytes, string $clientName): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, $bytes);

        $this->tempFixturePaths[] = $path;

        return new UploadedFile($path, $clientName, null, null, true);
    }
}
