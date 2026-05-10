<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\HabilidadController;

Route::prefix('habilidades')->middleware('auth:sanctum')->group(function () {
    Route::get('/catalogo', [HabilidadController::class, 'catalog']);
    Route::post('/catalogo', [HabilidadController::class, 'storeCatalog']);

    Route::get('/usuario/{userId}', [HabilidadController::class, 'indexUserSkills']);
    Route::get('/usuario/{userId}/{id}', [HabilidadController::class, 'showUserSkill']);
    Route::post('/usuario/{userId}', [HabilidadController::class, 'storeUserSkill']);
    Route::put('/usuario/{userId}/{id}', [HabilidadController::class, 'updateUserSkill']);
    Route::delete('/usuario/{userId}/{id}', [HabilidadController::class, 'destroyUserSkill']);
});