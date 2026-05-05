<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/github/connect-url', [AuthController::class, 'oauthConnectUrl'])
        ->defaults('provider', 'github');

    Route::delete('/github/unlink', [AuthController::class, 'oauthUnlink'])
        ->defaults('provider', 'github');

    Route::post('/github/repos/sync', [AuthController::class, 'syncGithubRepos']);
    Route::get('/github/repos/detected', [AuthController::class, 'githubDetectedRepos']);
    Route::post('/github/repos/languages', [AuthController::class, 'githubRepoLanguages']);
    Route::post('/github/repos/attach-to-project', [AuthController::class, 'attachDetectedReposToProject']);

});

Route::get('/github/redirect', [AuthController::class, 'oauthRedirect'])
    ->defaults('provider', 'github');

Route::get('/github/callback', [AuthController::class, 'oauthCallback'])
    ->defaults('provider', 'github');
