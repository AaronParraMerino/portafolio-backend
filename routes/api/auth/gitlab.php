<?php

use App\Http\Controllers\Api\Auth\GitlabAuthController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'account.writable'])->group(function () {
    Route::post('/gitlab/connect-url', [GitlabAuthController::class, 'connectUrl']);

    Route::delete('/gitlab/unlink', [GitlabAuthController::class, 'unlink']);

    Route::post('/gitlab/repos/sync', [GitlabAuthController::class, 'syncRepos']);
    Route::get('/gitlab/repos/detected/count', [GitlabAuthController::class, 'detectedReposCount']);
    Route::get('/gitlab/repos/detected', [GitlabAuthController::class, 'detectedRepos']);
    Route::post('/gitlab/repos/languages', [GitlabAuthController::class, 'repoLanguages']);
    Route::post('/gitlab/repos/attach-to-project', [GitlabAuthController::class, 'attachDetectedReposToProject']);
});

Route::get('/gitlab/redirect', [GitlabAuthController::class, 'redirect']);

Route::get('/gitlab/callback', [GitlabAuthController::class, 'callback']);
