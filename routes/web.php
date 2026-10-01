<?php

use App\Http\Controllers\FileController;
use Illuminate\Support\Facades\Route;

Route::get('/', [FileController::class, 'create'])->name('files.create');
Route::get('/files', [FileController::class, 'index'])->name('files.index');
Route::post('/files', [FileController::class, 'store'])->name('files.store');
Route::delete('/files/{storedFile}', [FileController::class, 'destroy'])->name('files.destroy');
