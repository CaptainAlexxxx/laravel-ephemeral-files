<?php

namespace App\Services;

use App\Models\StoredFile;
use App\Support\FileType;
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
        $type = FileType::detect($file) ?? throw new RuntimeException('Unsupported file type.');
        $disk = config('files.disk');
        $path = $file->storeAs('files', Str::uuid().'.'.$type, $disk);

        if ($path === false) {
            throw new RuntimeException('Failed to store the uploaded file.');
        }

        try {
            $now = now();

            $stored = new StoredFile([
                'original_name' => $file->getClientOriginalName(),
                'path' => $path,
                'mime_type' => FileType::mimeType($type),
                'size' => $file->getSize(),
                'expires_at' => $now->copy()->addMinutes(config('files.ttl_minutes')),
            ]);

            // one clock read so expires_at is exactly created_at + TTL
            $stored->forceFill(['created_at' => $now, 'updated_at' => $now])->save();

            return $stored;
        } catch (Throwable $e) {
            // no row means nothing would ever purge this file
            Storage::disk($disk)->delete($path);

            throw $e;
        }
    }
}
