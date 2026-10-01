<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * Detects the upload type from content, never from the client name or mime.
 */
final class FileType
{
    private const MIME_TYPES = [
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    /**
     * Returns 'pdf', 'docx' or null for anything else.
     */
    public static function detect(UploadedFile $file): ?string
    {
        return match ($file->guessExtension()) {
            'pdf' => 'pdf',
            'docx' => 'docx',
            'zip' => self::isDocx($file->getPathname()) ? 'docx' : null,
            default => null,
        };
    }

    public static function mimeType(string $type): string
    {
        return self::MIME_TYPES[$type];
    }

    // libmagic only scans the first few KB, so a DOCX with large docProps/ before word/ reads as plain zip
    private static function isDocx(string $path): bool
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }

        try {
            return $zip->locateName('[Content_Types].xml') !== false
                && $zip->locateName('word/document.xml') !== false;
        } finally {
            $zip->close();
        }
    }
}
