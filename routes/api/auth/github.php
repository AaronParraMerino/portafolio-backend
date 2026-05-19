<?php

use App\Http\Controllers\Api\Auth\GithubAuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/github/connect-url', [GithubAuthController::class, 'connectUrl']);

    Route::delete('/github/unlink', [GithubAuthController::class, 'unlink']);

    Route::post('/github/repos/sync', [GithubAuthController::class, 'syncRepos']);
    Route::get('/github/repos/detected/count', [GithubAuthController::class, 'detectedReposCount']);
    Route::get('/github/repos/detected', [GithubAuthController::class, 'detectedRepos']);
    Route::post('/github/repos/languages', [GithubAuthController::class, 'repoLanguages']);
    Route::post('/github/repos/attach-to-project', [GithubAuthController::class, 'attachDetectedReposToProject']);

});

Route::get('/github/redirect', [GithubAuthController::class, 'redirect']);

Route::get('/github/callback', [GithubAuthController::class, 'callback']);
