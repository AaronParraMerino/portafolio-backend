<?php

use App\Http\Controllers\Api\ProjectController;
use Illuminate\Support\Facades\Route;

Route::prefix('projects')->middleware('auth:sanctum')->group(function () {
    Route::get('/usuario/{userId}', [ProjectController::class, 'index']);
    Route::get('/{id}', [ProjectController::class, 'show']);
    Route::post('/', [ProjectController::class, 'store']);
    Route::put('/{id}', [ProjectController::class, 'update']);
    Route::delete('/{id}', [ProjectController::class, 'destroy']);
    Route::patch('/{id}/visibility', [ProjectController::class, 'updateVisibility']);
    Route::post('/{id}/image', [ProjectController::class, 'uploadImage']);
    Route::post('/{id}/image/update', [ProjectController::class, 'updateImage']);
    Route::delete('/{id}/image', [ProjectController::class, 'deleteImage']);
});