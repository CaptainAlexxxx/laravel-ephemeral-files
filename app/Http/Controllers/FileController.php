<?php

namespace App\Http\Controllers;

use App\Enums\DeletionReason;
use App\Http\Requests\StoreFileRequest;
use App\Models\StoredFile;
use App\Services\FileDeletionService;
use App\Services\FileUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class FileController extends Controller
{
    public function create(): View
    {
        return view('files.create');
    }

    public function index(): View|RedirectResponse
    {
        $files = StoredFile::query()->latest('id')->paginate(20);

        if ($files->currentPage() > $files->lastPage()) {
            return redirect($files->url($files->lastPage()));
        }

        return view('files.index', ['files' => $files]);
    }

    public function store(StoreFileRequest $request, FileUploadService $uploads): JsonResponse
    {
        $file = $uploads->store($request->file('file'));

        return response()->json([
            'data' => [
                'id' => $file->id,
                'original_name' => $file->original_name,
                'size' => $file->size,
                'mime_type' => $file->mime_type,
                'expires_at' => $file->expires_at->toIso8601ZuluString(),
            ],
        ], 201);
    }

    public function destroy(StoredFile $storedFile, FileDeletionService $deletions): JsonResponse
    {
        abort_unless($deletions->delete($storedFile, DeletionReason::Manual), 404);

        return response()->json(['message' => 'Deleted.']);
    }
}
