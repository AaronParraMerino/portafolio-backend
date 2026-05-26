<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TecnologiaController;

Route::get('/tecno', [TecnologiaController::class, 'index']);
Route::get('/tecno/get/{nombre}', [TecnologiaController::class, 'showByName']);

Route::middleware(['auth:sanctum', 'account.writable'])->group(function () {
    Route::post('/tecno/detectadas/batch', [TecnologiaController::class, 'storeDetectedBatch']);
    Route::post('/tecno/{nombre}', [TecnologiaController::class, 'store']);
    Route::patch('/tecno/{nombre}', [TecnologiaController::class, 'update']);
    Route::delete('/tecno/{nombre}', [TecnologiaController::class, 'destroy']);
});
