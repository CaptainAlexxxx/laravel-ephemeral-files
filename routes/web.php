<?php

use App\Http\Controllers\FileController;
use Illuminate\Support\Facades\Route;

Route::get('/', [FileController::class, 'create'])->name('files.create');
Route::get('/files', [FileController::class, 'index'])->name('files.index');
Route::post('/files', [FileController::class, 'store'])->middleware('throttle:20,1')->name('files.store');
Route::delete('/files/{storedFile}', [FileController::class, 'destroy'])->middleware('throttle:30,1')->name('files.destroy');
