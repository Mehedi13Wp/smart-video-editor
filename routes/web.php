<?php
use App\Http\Controllers\MediaController;
use App\Http\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;
Route::get('/', [ProjectController::class, 'index'])->name('dashboard');
Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
Route::patch('/projects/{project}', [ProjectController::class, 'update']);
Route::delete('/projects/{project}', [ProjectController::class, 'destroy']);
Route::get('/projects/{project}/local-imports', [MediaController::class, 'localImports']);
Route::post('/projects/{project}/local-imports', [MediaController::class, 'importLocal']);
Route::post('/projects/{project}/media', [MediaController::class, 'store']);
Route::get('/projects/{project}/media/{media}/stream', [MediaController::class, 'stream'])->name('media.stream');
Route::patch('/projects/{project}/media/{media}', [MediaController::class, 'update']);
Route::delete('/projects/{project}/media/{media}', [MediaController::class, 'destroy']);
Route::post('/projects/{project}/media/{media}/remixes', [\App\Http\Controllers\RemixController::class, 'store']);
Route::get('/projects/{project}/media/{media}/remixes', [\App\Http\Controllers\RemixController::class, 'index']);
Route::get('/projects/{project}/media/{media}/remixes/{remix}', [\App\Http\Controllers\RemixController::class, 'show']);
Route::get('/projects/{project}/media/{media}/remixes/{remix}/stream', [\App\Http\Controllers\RemixController::class, 'stream']);
