<?php

namespace App\Services;

use App\Models\StoredFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class FileUploadService
{
    /**
     * Expects a file already validated by StoreFileRequest.
     */
    public function store(UploadedFile $file): StoredFile
    {
        $disk = config('files.disk');
        $path = $file->storeAs('files', Str::uuid().'.'.$file->guessExtension(), $disk);

        if ($path === false) {
            throw new RuntimeException('Failed to store the uploaded file.');
        }

        try {
            return StoredFile::create([
                'original_name' => $file->getClientOriginalName(),
                'path' => $path,
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'expires_at' => now()->addMinutes(config('files.ttl_minutes')),
            ]);
        } catch (Throwable $e) {
            // no row means nothing would ever purge this file
            Storage::disk($disk)->delete($path);

            throw $e;
        }
    }
}
