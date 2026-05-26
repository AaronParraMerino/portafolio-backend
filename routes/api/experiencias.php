<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ExperienciaController;

Route::prefix('experiencias')->middleware(['auth:sanctum', 'account.writable'])->group(function () {
    Route::get('/usuario/{userId}', [ExperienciaController::class, 'index']);
    Route::get('/usuario/{userId}/{id}', [ExperienciaController::class, 'show']);
    Route::post('/usuario/{userId}', [ExperienciaController::class, 'store']);
    Route::put('/usuario/{userId}/{id}', [ExperienciaController::class, 'update']);
    Route::delete('/usuario/{userId}/{id}', [ExperienciaController::class, 'destroy']);
});
